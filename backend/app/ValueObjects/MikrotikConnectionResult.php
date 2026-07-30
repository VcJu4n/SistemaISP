<?php

namespace App\ValueObjects;

final readonly class MikrotikConnectionResult
{
    public function __construct(
        public bool $connected,
        public ?string $error = null,
    ) {}

    public static function connected(): self
    {
        return new self(true);
    }

    public static function disconnected(string $error): self
    {
        return new self(false, mb_substr($error, 0, 2000));
    }
}
