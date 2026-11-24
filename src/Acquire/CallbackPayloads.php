<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

/**
 * Builds the `detail` of the upload-related callbacks so the job stays thin
 * wiring and the payloads are unit-tested.
 *
 * Ledge stores `detail.reason` (cut to 255 characters) on every event and its
 * telemetry keys on `acquire.completed`. Everything else is stored as the
 * event's metrics after bounding (20 keys per level, strings cut to 255
 * characters, nulls dropped, two levels of nesting), and replaced by
 * `{"truncated": true}` when the JSON of that is over 1500 bytes. So each
 * event carries one attempt, never the history, and fit() keeps the rest
 * under STORED_DETAIL_BUDGET.
 */
class CallbackPayloads
{
    /**
     * Ledge's 1500-byte limit on an event's stored metrics
     * (BackupRunEventController), less a margin for differences in how the
     * two sides encode JSON.
     */
    public const STORED_DETAIL_BUDGET = 1400;

    // Ledge cuts every stored string to this many characters.
    private const STORED_STRING_CHARS = 255;

    // What error and detail are cut to when shedding is not enough.
    private const SHED_STRING_CHARS = 120;

    /**
     * `acquire.upload_retried`: sent before each retry wait, with the attempt
     * that just failed.
     *
     * @return array<string, mixed>
     */
    public static function uploadRetried(UploadDiagnostics $soFar): array
    {
        $last = $soFar->lastAttempt();
        $payload = [
            'reason' => $soFar->lastFailureSummary() ?? 'upload attempt failed',
            'max_attempts' => $soFar->maxAttempts,
            'retry_in_ms' => $last !== null && $last->retryInMs !== null ? $last->retryInMs : 0,
            'upload_attempts' => $soFar->attemptCount(),
        ];

        if ($last !== null) {
            $payload['upload_attempt'] = $last->toArray();
        }

        return self::fit($payload);
    }

    /**
     * `acquire.failed` from any step. `reason` is the bare code, except for
     * `upload_failed`, which gets `upload_failed: <line of the last attempt>`
     * (Ledge matches `upload_failed` by prefix, but other codes such as
     * `bundle_too_large` exactly). A failure at the upload step also carries
     * the attempt count and the last attempt.
     *
     * @param  array<string, int|null>  $timings  measured phase durations; nulls are dropped
     * @return array<string, mixed>
     */
    public static function failed(string $reasonCode, ?string $detail, array $timings, int $peakMemoryBytes, ?UploadDiagnostics $upload = null): array
    {
        $payload = [
            'reason' => $reasonCode,
            'reason_code' => $reasonCode,
            'detail' => $detail,
        ];

        if ($upload !== null) {
            if ($reasonCode === 'upload_failed') {
                $prefix = "{$reasonCode}: ";
                $maxLength = UploadSummary::MAX_LENGTH - strlen($prefix);
                $summary = $upload->lastFailureSummary($maxLength) ?? UploadSummary::cap((string) $detail, $maxLength);
                $payload['reason'] = $summary === '' ? $reasonCode : $prefix.$summary;
            }

            $payload['upload_attempts'] = $upload->attemptCount();
            $last = $upload->lastAttempt();

            if ($last !== null) {
                $payload['upload_attempt'] = $last->toArray();
            }
        }

        return self::fit($payload + self::timings($timings) + ['peak_memory_bytes' => $peakMemoryBytes]);
    }

    /**
     * `acquire.completed`: the profile's own keys unchanged, plus phase
     * timings, attempt count and peak memory.
     *
     * @param  array<string, mixed>  $profileDetail
     * @param  array<string, int|null>  $timings
     * @return array<string, mixed>
     */
    public static function completed(array $profileDetail, array $timings, int $peakMemoryBytes, UploadDiagnostics $upload): array
    {
        return self::fit($profileDetail + self::timings($timings) + [
            'upload_attempts' => $upload->attemptCount(),
            'peak_memory_bytes' => $peakMemoryBytes,
        ]);
    }

    /**
     * Upper bound on the JSON Ledge would store for this detail outside
     * `reason`: strings counted at no more than 255 characters, and (unlike
     * Ledge) nulls and telemetry keys counted too.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function storedSize(array $detail): int
    {
        unset($detail['reason']);

        return strlen((string) json_encode(self::boundStrings($detail), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Sheds the least useful parts of the attempt until the detail fits the
     * budget: the response body, then the request-id map, then `error` and
     * `detail` cut to 120 characters, and as a last resort the attempt
     * object itself. `reason`, `reason_code`, `upload_attempts` and the
     * timings are never touched.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function fit(array $detail): array
    {
        $steps = [
            static function (array $d): array {
                if (isset($d['upload_attempt']) && is_array($d['upload_attempt'])) {
                    $d['upload_attempt']['body'] = null;
                }

                return $d;
            },
            static function (array $d): array {
                if (isset($d['upload_attempt']) && is_array($d['upload_attempt'])) {
                    $d['upload_attempt']['request_ids'] = null;
                }

                return $d;
            },
            static function (array $d): array {
                if (isset($d['upload_attempt']['error']) && is_string($d['upload_attempt']['error'])) {
                    $d['upload_attempt']['error'] = mb_substr($d['upload_attempt']['error'], 0, self::SHED_STRING_CHARS, 'UTF-8');
                }

                if (isset($d['detail']) && is_string($d['detail'])) {
                    $d['detail'] = mb_substr($d['detail'], 0, self::SHED_STRING_CHARS, 'UTF-8');
                }

                return $d;
            },
            static function (array $d): array {
                unset($d['upload_attempt']);

                return $d;
            },
        ];

        foreach ($steps as $step) {
            if (self::storedSize($detail) <= self::STORED_DETAIL_BUDGET) {
                break;
            }

            $detail = $step($detail);
        }

        return $detail;
    }

    /**
     * @param  array<string, int|null>  $timings
     * @return array<string, int>
     */
    private static function timings(array $timings): array
    {
        return array_filter($timings, static fn (?int $ms): bool => $ms !== null);
    }

    private static function boundStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, self::STORED_STRING_CHARS, 'UTF-8');
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::boundStrings($item), $value);
        }

        return $value;
    }
}
