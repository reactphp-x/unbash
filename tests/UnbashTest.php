<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use ReactphpX\Unbash\Result;
use ReactphpX\Unbash\Unbash;

final class UnbashTest extends TestCase
{
    public function testRunCapturesStdoutAndZeroExitCode(): void
    {
        $result = $this->await(Unbash::run('echo hello'));

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('hello', trim($result->stdout));
        $this->assertTrue($result->isSuccessful());
    }

    public function testRunCapturesStderrAndNonZeroExitCode(): void
    {
        $result = $this->await(Unbash::run('echo oops >&2; exit 3'));

        $this->assertSame(3, $result->exitCode);
        $this->assertSame('oops', trim($result->stderr));
        $this->assertFalse($result->isSuccessful());
    }

    public function testCommandsRunConcurrentlyWithoutBlocking(): void
    {
        $order = [];

        Unbash::run('sleep 1 && echo slow')->then(function () use (&$order): void {
            $order[] = 'slow';
        });
        Unbash::run('echo fast')->then(function () use (&$order): void {
            $order[] = 'fast';
        });

        Loop::run();

        $this->assertSame(['fast', 'slow'], $order);
    }

    /**
     * @return Result
     */
    private function await(\React\Promise\PromiseInterface $promise): Result
    {
        $resolved = null;
        $promise->then(function (Result $result) use (&$resolved): void {
            $resolved = $result;
        });

        Loop::run();

        $this->assertNotNull($resolved, 'Promise did not resolve');

        return $resolved;
    }
}
