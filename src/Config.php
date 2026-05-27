<?php

declare(strict_types=1);

namespace Exp\NaviiData;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('config must return array: ' . $path);
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
