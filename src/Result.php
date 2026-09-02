<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * The outcome of a finished command: its exit code and captured output.
 */
final class Result
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
