# Ledge Acquire Protocol (Laravel, `backup` profile)

The acquire capability lets Ledge command an application to produce an encrypted bundle and push it to object storage. On Laravel the package ships one profile, `backup`: an encrypted database dump for scheduled backups. It is a higher-privilege capability than `/health`, so commands must carry an Ed25519 signature verifiable independently of the shared `X-Ledge-Key`.

The wire protocol, verification chain, bundle format, callbacks, and status endpoint are identical to the Craft plugin's (`ledge-craft/docs/acquire-protocol.md`). This document is the Laravel view of it; the Craft document remains the reference for the `full` profile.

## Key discovery

The package does **not** store a signing public key. Ledge publishes its signing keys at a well-known keyset endpoint and the package discovers them:

```
GET {ledge_base_url}/.well-known/ledge-keys

{ "keys": [ { "key_id": "a1b2c3d4", "alg": "ed25519", "public_key": "<base64>" } ] }
```

- `ledge_base_url` defaults to `https://my.ledgehq.app` and is overridable **only** in the published `config/ledge.php`. It is deliberately not read from the environment and never derived from an incoming command: an attacker-supplied URL serving an attacker key would defeat the scheme.
- A command with an unknown `key_id` triggers one refetch; if still unknown, the command is rejected (`unknown_key_id`).
- **Pins are permanent.** Learned `key_id` → `public_key` pins are stored in the cache without TTL: a `key_id` that was ever seen maps to its original key material forever. A later fetch that disagrees is ignored for that entry, even after the fetched-keyset cache (24h TTL, discovery only) expires. Rotation always arrives as a new `key_id`.

## Configuration

```php
// config/ledge.php (publish with `php artisan vendor:publish --tag=ledge-config`)
return [
    // Off by default. While off the acquire routes are not registered (404).
    'acquire_enabled' => env('LEDGE_ACQUIRE_ENABLED', false),
    'acquire_path' => env('LEDGE_ACQUIRE_PATH', '_ledge/acquire'),

    // Upload/callback host allowlist. Defaults to ledgehq.app and *.ledgehq.app;
    // setting it replaces the default entirely.
    'acquire_allowed_hosts' => env('LEDGE_ACQUIRE_ALLOWED_HOSTS', ['ledgehq.app', '*.ledgehq.app']),
    'acquire_allow_insecure' => env('LEDGE_ACQUIRE_ALLOW_INSECURE', false), // plain http, local dev only

    'acquire_max_bundle_bytes' => env('LEDGE_ACQUIRE_MAX_BUNDLE_BYTES', 524288000),
    'acquire_job_timeout' => env('LEDGE_ACQUIRE_JOB_TIMEOUT', 3600),
    'acquire_queue' => env('LEDGE_ACQUIRE_QUEUE'),                       // app default queue unless set
    'acquire_queue_connection' => env('LEDGE_ACQUIRE_QUEUE_CONNECTION'), // app default connection unless set
    'acquire_tmp_path' => env('LEDGE_ACQUIRE_TMP_PATH'),                 // storage/framework/ledge unless set
    'acquire_dump_binary_path' => env('LEDGE_ACQUIRE_DUMP_BINARY_PATH'), // directory holding mysqldump etc.

    'ledge_base_url' => 'https://my.ledgehq.app', // trust anchor; file-config only
];
```

`upload_url` and `callback_url` must be `https` (plain `http` only with `acquire_allow_insecure`) and their hosts must match the allowlist (exact, case-insensitive, or `*.example.com` wildcard); non-matching commands are rejected `host_not_allowed`.

The `/health` payload advertises the capability:

```json
{ "acquireEnabled": true, "capabilities": ["backup"], "updateTest": { "strategy": "testsuite" } }
```

`capabilities` lists the registered profiles when acquire is on (empty when off). `updateTest.strategy` tells Ledge how to test updates for this platform: Laravel apps run their own test suite; Craft sites are crawled. Ledge reads `capabilities` and, for Craft plugins that predate it, the `acquireEnabled` / `backupProfileSupported` booleans; this package no longer sends `backupProfileSupported`.

## Command contract

`POST /_ledge/acquire` with header `X-Ledge-Key: <shared key>` (header name configurable with `key_header`; the query-parameter fallback is never accepted on acquire routes) and body:

```json
{
  "command": {
    "run_id": "9f2b3c-...",        // 1-64 chars, [A-Za-z0-9-]
    "expires_at": "2026-10-22T12:15:00+00:00",   // ISO8601
    "upload_url": "https://storage.ledgehq.app/bundles/<run_id>",
    "bundle_pubkey": "<base64 X25519 public key (crypto_box), 32 bytes>",
    "profile": "backup",
    "callback_url": "https://app.ledgehq.app/api/acquire/callback",
    "callback_token": "<bearer token echoed on every callback>",
    "key_id": "a1b2c3d4"
  },
  "signature": "<base64 Ed25519 detached signature>"
}
```

The signature covers the **canonical JSON of the command without `callback_token`**: recursively sort all keys (`ksort`), then `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Excluding `callback_token` lets Ledge rotate callback tokens without re-signing; the token is still protected in transit by TLS, and `callback_url` itself *is* signed and allowlisted.

## Profiles

Verification, replay guard, host allowlist, encryption, and the signed-upload flow are identical across profiles; only the archive contents and the `acquire.completed` detail differ. Profiles are a registry on `AcquireService`: the base package registers `backup`, and a platform layer may register more (`$acquire->registerProfile('full', FullProfileJob::class)`, where the job extends `AbstractAcquireJob`). Unregistered profile values are rejected `invalid_profile`.

| Profile | Purpose | Bundle contents | `acquire.completed` detail |
|---|---|---|---|
| `backup` | Scheduled backup | `dump.sql` **only** | `{size, sha256, compressed_size, uncompressed_size, dump_duration_ms, table_count}` + upload telemetry |

The `backup` profile is deliberately **the database and nothing else**: no env manifest, no metadata sidecar. The completed detail is operational telemetry only (sizes, dump duration, table count, sealed sha256), no application data.

## Verification order and error reasons

Checks run in this order; the first failure wins. Errors are `{"accepted": false, "reason": "<reason>"}` with the listed HTTP status, and every rejection is logged.

| Order | Check | Reason | Status |
|---|---|---|---|
| 0 | acquire enabled | *(route not registered)* | 404 |
| 1 | `X-Ledge-Key` valid | `invalid_key` | 401 |
| 2 | body well-formed (`command` object + base64 64-byte `signature`) | `invalid_envelope` | 400 |
| 3 | command fields present/typed, ISO8601 `expires_at` | `invalid_payload` | 400 |
| 4 | `key_id` resolves via keyset | `unknown_key_id` / `keyset_unavailable` | 401 / 503 |
| 5 | Ed25519 signature valid over canonical JSON | `invalid_signature` | 401 |
| 6 | not expired | `expired` | 403 |
| 7 | profile registered | `invalid_profile` | 400 |
| 8 | upload + callback hosts allowlisted, https | `host_not_allowed` | 403 |
| 9 | `run_id` never seen before | `replayed` | 409 |
| 10 | job dispatched to the queue | `queue_unavailable` | 500 |

Success: `202 {"accepted": true, "run_id": "..."}`. The job is dispatched on the configured queue; nothing heavy runs in the request cycle. A `queue_unavailable` failure releases the `run_id`, so the same command can be retried.

Job-side failure reasons (reported via callback and the status endpoint): `no_dump_command` (dump binary not resolvable, or the driver is not MySQL/MariaDB/PostgreSQL/SQLite), `tmp_not_writable`, `insufficient_disk` (free < 2× estimated dump size, or the size could not be estimated), `sodium_unavailable`, `dump_failed`, `archive_failed`, `bundle_too_large`, `encrypt_failed`, `upload_failed`, `run_already_finished`, `unexpected_error`. The job runs once (`tries = 1`); Ledge re-issues a fresh `run_id` rather than retrying a run that failed.

**Upload semantics:** the bundle is a streamed `PUT` to `upload_url`, retried on transient failures, the same way for every profile (the shared `AbstractAcquireJob` pipeline). Any 2xx response is success and the body is ignored (the Ledge signed route answers `201 {"ok":true,...}`; an S3 pre-signed PUT answers `200` with an empty body). A PUT to the same URL overwrites the same object, so repeating it is safe; every attempt reopens the already-encrypted bundle and sends the whole body with the same `Content-Length` (nothing is re-dumped or re-encrypted).

- **Retried:** transport failures with no response (connect errors, resets, timeouts) and HTTP `408`, `429`, `500`, `502`, `503` and `504`.
- **Terminal, never retried:** `413` → `bundle_too_large`, `409` (run already finished) → `run_already_finished`, any other status → `upload_failed` (other 4xx such as `403` for an expired or invalid signature, other 5xx such as `501`, and 3xx since redirects are not followed).
- **Attempts and waits:** up to 5 attempts. The waits before attempts 2-5 are 2s, 6s, 18s and 54s, each multiplied by a random jitter in [0.75, 1.25] so sites backing up in the same minute spread out: about 80s of waiting in all. A `Retry-After` header (delta-seconds or HTTP-date) longer than the computed wait replaces it, capped at 60s.
- **Deadline:** Ledge's upload URL is valid for 60 minutes from when it sent the command, and Ledge reaps a run still unfinished 60 minutes after it started. The job records when the command was accepted, and no retry starts at or after accepted + 55 minutes; each attempt's timeout is also clipped to `max(30s, min(600s, time left before that deadline))`, so an attempt cannot run on past the window. Jobs queued by 1.2.0 (or built without the trailing `acceptedAt` constructor argument) carry no accepted time and upload without the deadline.
- **Time budget:** independently, no new attempt starts once the time since the first attempt began would exceed the per-attempt timeout (600s), so a run of slow timeouts cannot use up the job's `$timeout` (3600s).
- When retries stop, the `detail` string says why, e.g. `upload target responded with HTTP 503 after 5 attempts`, `… (retry time budget of 600s exhausted)` or `… (upload URL deadline reached)`.

Before 1.3.0 this package (like the Craft plugin before 5.14.0 / 4.14.0) made a single attempt and gave up on the first failure.

## Database dump

The dump is produced through the `LedgeHQ\Ledge\Acquire\Database\DatabaseDumper` contract. The default binding, `SpatieDatabaseDumper`, reads the app's default `database.connections.*` entry (including a `url` DSN) and shells out through `spatie/db-dumper`:

| Driver | Binary |
|---|---|
| `mysql` | `mysqldump` |
| `mariadb` | `mariadb-dump` |
| `pgsql` | `pg_dump` |
| `sqlite` | `sqlite3` |

Set `acquire_dump_binary_path` when the binary is not on the worker's `PATH`. Preflight resolves the binary before any work starts and estimates the dump size from `Schema::getTables()` (file size for SQLite). To use a different pipeline, bind your own implementation of `DatabaseDumper` in the container.

## Bundle file format

The uploaded object is:

```
[ 80 bytes  crypto_box_seal(symmetric key, bundle_pubkey) ]
[ 24 bytes  secretstream header ]
[ N chunks  crypto_secretstream_xchacha20poly1305 ciphertext ]
```

- Symmetric key: `crypto_secretstream_xchacha20poly1305_keygen()` (32 bytes); sealed size = 32 + 48 (`SODIUM_CRYPTO_BOX_SEALBYTES`).
- Plaintext chunk size: **1 MiB** (1048576 bytes); each ciphertext chunk is 1048576 + 17 (`SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES`) bytes, except the final chunk, which is shorter and tagged `TAG_FINAL`.
- The plaintext is `bundle.tar.gz`: a gzipped tar containing `dump.sql`.

Runner-side decrypt:

```php
$in = fopen($bundlePath, 'rb');
$key = sodium_crypto_box_seal_open(fread($in, 80), $runnerKeypair); // crypto_box keypair matching bundle_pubkey
$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(fread($in, 24), $key);
$out = fopen('bundle.tar.gz', 'wb');

do {
    $chunk = fread($in, 1048576 + 17);
    [$plain, $tag] = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
    fwrite($out, $plain);
} while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
```

## Callbacks

Best-effort `POST` to `callback_url` with `Authorization: Bearer <callback_token>` (3s timeout, one retry; a dropped callback never fails the job). The body is:

```json
{ "event": "acquire.progress", "step": "dump", "detail": null }
```

| Event | When | Detail |
|---|---|---|
| `acquire.accepted` | command verified + job dispatched | — |
| `acquire.started` | job picked up by a worker | — |
| `acquire.progress` | after each step (`preflight`, `dump`, `manifest`, `encrypt`) | — |
| `acquire.upload_retried` | an upload attempt failed and will be retried; sent before the wait (step `upload`) | see below |
| `acquire.completed` | bundle uploaded | the profile's keys (see Profiles) + upload telemetry, see below |
| `acquire.failed` | any step failed; at the upload step only once retries are exhausted or the status is terminal | see below |

The `manifest` step is kept for parity with the Craft plugin's step list even though the `backup` profile adds nothing in it.

`acquire.accepted` is sent after the job is dispatched. On the `sync` queue driver the whole run therefore happens inside the request and `accepted` arrives after `completed`; Ledge treats late events as already handled. Use a real queue driver for backups. Laravel queues have no job priority, so to keep a backup from waiting behind a backlog, set `acquire_queue` to a dedicated queue and list it first on the worker (`queue:work --queue=ledge,default`) or in Horizon's supervisor `queue`.

How Ledge treats these today: **any event whose name ends in `.failed` is terminal** (the run is marked failed and the customer alerted), so the package sends `acquire.failed` once and never for a retried attempt. Other events are appended to the run's event log. Per event, Ledge keeps:

- `detail.reason`, cut to 255 characters;
- on `acquire.completed`, the scalar telemetry keys `sha256`, `size`, `compressed_size`, `uncompressed_size`, `dump_duration_ms`, `table_count`, `encrypt_duration_ms`, `upload_duration_ms`, `upload_attempts`, `peak_memory_bytes`;
- everything else in `detail` as the event's metrics, after bounding: the first 20 keys at each level, strings cut to 255 characters, nulls dropped, at most two levels of nesting below the top. If the JSON of that is over 1500 bytes, the whole of it is replaced by `{"truncated": true}`.

That last limit is why each event carries a single upload attempt rather than the history, and why the package keeps the rest of `detail` under 1400 bytes as Ledge would store it: when a worst-case attempt would not fit, it drops the attempt's `body`, then its `request_ids`, then cuts the attempt's `error` and the top-level `detail` to 120 characters, and as a last resort leaves the attempt object out. `reason`, `reason_code`, `upload_attempts` and the timings are never dropped. A typical S3 `503 SlowDown` attempt fits whole (about 700 bytes stored for `acquire.upload_retried`, 850 for the final `acquire.failed`). The keys below are additive and absent before 1.3.0 of this package (and on Craft plugins before 5.14.0 / 4.14.0).

### Upload retries and diagnostics

**Reason line.** Each failed upload attempt is summarised in one line, which is what Ledge stores:

```
attempt 2/5: HTTP 503 SlowDown, request-id abc123, 412 ms, sent 0/26214400 bytes, retry-after 1
attempt 1/5: ConnectException curl 28, 600012 ms, sent 1048576/26214400 bytes
```

The parts are the attempt number out of the maximum; `HTTP <status>` with the S3 error code when the body has one, and/or the exception type with curl's error number; `request-id`; the attempt's duration; `sent <bytes>/<bundle size> bytes`; and `retry-after`. Parts whose value is unknown are left out. Values taken from the response are reduced to printable ASCII and capped, and the line (with any `<code>: ` prefix) is always under 255 characters.

**`acquire.upload_retried`** (step `upload`):

| Key | Type | |
|---|---|---|
| `reason` | string | the reason line of the attempt that just failed |
| `max_attempts` | int | attempts allowed (5) |
| `retry_in_ms` | int | wait before the next attempt |
| `upload_attempts` | int | attempts made so far |
| `upload_attempt` | object | the attempt that just failed (below) |

A dropped or failing callback for this event neither fails the job nor uses up an attempt.

**`acquire.failed`** from any step:

| Key | Type | |
|---|---|---|
| `reason` | string | the bare reason code (`no_dump_command`, `dump_failed`, `bundle_too_large`, `run_already_finished`, ...), exactly as before, except `upload_failed`, which becomes `upload_failed: <reason line of the last attempt>`, e.g. `upload_failed: attempt 5/5: HTTP 503 SlowDown, request-id abc123, 412 ms, sent 0/26214400 bytes` (Ledge matches `upload_failed` by prefix and other codes exactly) |
| `reason_code` | string | the bare code, on every failed event |
| `detail` | string\|null | the human-readable detail, as before |
| `upload_attempts` | int | upload step only: attempts made |
| `upload_attempt` | object | upload step only: the last attempt (below); absent when none was made |
| `dump_duration_ms`, `encrypt_duration_ms`, `upload_duration_ms` | int | whichever phases had been measured (for an upload failure, `upload_duration_ms` is the time spent uploading, waits included) |
| `peak_memory_bytes` | int | `memory_get_peak_usage(true)` |

The status endpoint keeps recording the bare code.

**`acquire.completed`**: the profile's existing keys, unchanged, plus:

| Key | Type | |
|---|---|---|
| `encrypt_duration_ms` | int | archive + encryption time |
| `upload_duration_ms` | int | upload time, waits included |
| `upload_attempts` | int | attempts made, `1` when the first succeeded |
| `peak_memory_bytes` | int | `memory_get_peak_usage(true)` |

Earlier failed attempts of a run that then succeeded were reported by their own `acquire.upload_retried` events.

**Attempt object** (`upload_attempt`):

| Key | Type | |
|---|---|---|
| `attempt` | int | 1-based |
| `started_at` | string | UTC, `2026-11-24T03:00:07Z` |
| `status` | int\|null | `null` when no response arrived. When the transfer broke after the response headers came in, both `status` and `error` are set and the attempt is judged by its status |
| `error` | string\|null | exception type and message, at most 200 bytes; `null` when a response arrived cleanly |
| `curl_errno` | int\|null | `28` timeout, `7` couldn't connect, `56` receive failure, ... |
| `error_code` | string\|null | the S3 `<Code>` from the response body (`SlowDown`, `ServiceUnavailable`, ...), at most 64 characters of `[A-Za-z0-9._-]` |
| `request_id` | string\|null | `x-amz-request-id` if present, else the first other request-id header |
| `request_ids` | object\|null | every response header whose name contains `request-id` or `amz-id`, or is `cf-ray`: name → value, at most 5, values at most 128 bytes; `null` if shed to fit the size budget. No other headers are reported |
| `retry_after` | string\|null | the raw `Retry-After` header, at most 64 bytes |
| `duration_ms` | int | |
| `bytes_sent` | int\|null | bytes of the body curl had sent. Guzzle sends `Expect: 100-continue` for bodies over 1 MB, so a store that throttles can answer before any of the body is sent; `0` here shows that. `null` when the HTTP handler gives no stats |
| `remote_ip` | string\|null | the address curl connected to |
| `body` | string\|null | the first 300 bytes of a non-2xx response body, valid UTF-8; `null` if shed to fit the size budget |
| `retry_in_ms` | int\|null | wait before the next attempt; `null` when none followed |

No reason, detail or attempt object ever contains the upload URL's query string, the callback token or the bundle key: the URL is reduced to scheme + host + path, and the query string, its long values, the callback token and the bundle public key are replaced with `[redacted]` wherever a response or exception message echoes them.

## Status endpoint (pull fallback)

`GET /_ledge/acquire/<run_id>` with the `X-Ledge-Key` header returns the run's current state (held in the app's default cache store, ~24h TTL; the package keeps no persistent run history, Ledge is the system of record):

```json
{
  "run_id": "...",
  "status": "pending|running|completed|failed",
  "step": "queued|preflight|dump|manifest|encrypt|upload",
  "detail": null,
  "profile": "backup",
  "size": 1234567,
  "sha256": "…",
  "dateCreated": "…",
  "dateUpdated": "…"
}
```

Unknown run: `404 {"reason": "unknown_run_id"}`. Because run state is cache-backed, `cache:clear` mid-run drops the entry (the watchdog should treat unknown as retryable); the job rebuilds it on its next transition.

## Local dev

```php
// config/ledge.php in the test app
return [
    'acquire_enabled' => true,
    'acquire_allowed_hosts' => ['ledge.test'],
    'acquire_allow_insecure' => true,
    'ledge_base_url' => 'https://ledge.test',
];
```

1. Mint a signed command from the local Ledge, pointing `upload_url` at a local PUT sink.
2. `curl -X POST -H "X-Ledge-Key: …" -d @body.json https://app.test/_ledge/acquire` → expect `202`.
3. Re-POST the same body → `409 replayed`. Change a signed field → `401 invalid_signature`. Set `expires_at` in the past → `403 expired`. Use an off-list host → `403 host_not_allowed`. Use a bogus `key_id` → `401 unknown_key_id`.
4. `php artisan queue:work --once` → callbacks arrive in order; `GET /_ledge/acquire/<run_id>` shows `completed` with `size`/`sha256`; decrypt the uploaded object with the runner snippet and inspect `dump.sql`.
5. Confirm `storage/framework/ledge/` is empty afterwards, including after a forced failure (e.g. an unreachable `upload_url`).
