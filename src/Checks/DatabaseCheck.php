<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class DatabaseCheck implements CheckInterface
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'database';
    }

    public function run(): ?CheckResult
    {
        $start = microtime(true);

        try {
            $connection = $this->db->connection();
            $connection->select('SELECT 1');
            $responseTime = round((microtime(true) - $start) * 1000);

            return CheckResult::healthy($this->getName(), [
                'responseTime' => $responseTime,
                'driver' => $connection->getDriverName(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Ledge database check failed: '.$e->getMessage());

            return CheckResult::unhealthy($this->getName(), [], 'Database connection failed');
        }
    }
}
