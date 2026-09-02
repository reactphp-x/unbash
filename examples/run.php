<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ReactphpX\Unbash\Result;
use ReactphpX\Unbash\Unbash;

// Two commands are started back-to-back. The slower one is kicked off first,
// but because nothing blocks the event loop the faster one finishes first.
Unbash::run('sleep 1 && echo "slow command done"')
    ->then(function (Result $result): void {
        echo "[slow] exit={$result->exitCode} stdout=" . trim($result->stdout) . "\n";
    });

Unbash::run('echo "fast command done"')
    ->then(function (Result $result): void {
        echo "[fast] exit={$result->exitCode} stdout=" . trim($result->stdout) . "\n";
    });

Unbash::run('echo "this fails" >&2; exit 7')
    ->then(function (Result $result): void {
        echo "[fail] exit={$result->exitCode} stderr=" . trim($result->stderr)
            . " successful=" . ($result->isSuccessful() ? 'true' : 'false') . "\n";
    });

echo "all commands started; the event loop is free\n";
