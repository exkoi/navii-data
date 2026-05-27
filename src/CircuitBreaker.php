<?php

declare(strict_types=1);

namespace Exp\NaviiData;

final class CircuitBreaker
{
    private int $consecutiveFailures = 0;

    public function __construct(
        private string $stopFile,
        private int $threshold,
        private Logger $logger,
    ) {}

    public function isStopped(): bool
    {
        return file_exists($this->stopFile);
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
    }

    public function recordFailure(string $reason): void
    {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= $this->threshold) {
            $this->trip("consecutive failures reached threshold ({$this->threshold}): {$reason}");
        }
    }

    public function trip(string $reason): void
    {
        @file_put_contents(
            $this->stopFile,
            sprintf("tripped at %s: %s\n", date('Y-m-d H:i:sP'), $reason)
        );
        $this->logger->error("CIRCUIT BREAKER TRIPPED: {$reason}");
    }
}
