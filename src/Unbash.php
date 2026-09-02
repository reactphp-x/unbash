<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Run shell commands without blocking the event loop.
 *
 * Instead of blocking calls like shell_exec()/exec(), each command runs as a
 * child process and resolves a promise with a {@see Result} once it exits, so
 * many commands can run concurrently on a single ReactPHP event loop.
 */
final class Unbash
{
    /**
     * @param string $command shell command line to execute
     * @return PromiseInterface<Result>
     */
    public static function run(string $command): PromiseInterface
    {
        $process = new Process($command);
        $process->start();

        $stdout = '';
        $stderr = '';

        if ($process->stdout !== null) {
            $process->stdout->on('data', function (string $chunk) use (&$stdout): void {
                $stdout .= $chunk;
            });
        }

        if ($process->stderr !== null) {
            $process->stderr->on('data', function (string $chunk) use (&$stderr): void {
                $stderr .= $chunk;
            });
        }

        /** @var Deferred<Result> $deferred */
        $deferred = new Deferred();

        $process->on('exit', function (?int $exitCode) use ($deferred, &$stdout, &$stderr): void {
            $deferred->resolve(new Result((int) $exitCode, $stdout, $stderr));
        });

        return $deferred->promise();
    }
}
