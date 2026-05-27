<?php

declare(strict_types=1);

namespace Exp\NaviiData;

final class RateLimiter
{
    public function __construct(
        private int $minSec,
        private int $maxSec,
    ) {
        if ($minSec < 1) {
            $minSec = 1;
        }
        if ($maxSec < $minSec) {
            $maxSec = $minSec;
        }
        $this->minSec = $minSec;
        $this->maxSec = $maxSec;
    }

    public function sleep(): void
    {
        $sec = random_int($this->minSec, $this->maxSec);
        sleep($sec);
    }
}
