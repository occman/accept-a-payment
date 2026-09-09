<?php

namespace Tests;

final class ScriptResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
