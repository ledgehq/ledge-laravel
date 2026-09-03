<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

class CheckResult
{
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNHEALTHY = 'unhealthy';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $name,
        public string $status,
        public array $meta = [],
        public ?string $output = null,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function healthy(string $name, array $meta = [], ?string $output = null): self
    {
        return new self($name, self::STATUS_HEALTHY, $meta, $output);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function degraded(string $name, array $meta = [], ?string $output = null): self
    {
        return new self($name, self::STATUS_DEGRADED, $meta, $output);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function unhealthy(string $name, array $meta = [], ?string $output = null): self
    {
        return new self($name, self::STATUS_UNHEALTHY, $meta, $output);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status,
            ...$this->meta,
        ];

        if ($this->output !== null) {
            $result['output'] = $this->output;
        }

        return $result;
    }
}
