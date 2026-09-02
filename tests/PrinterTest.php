<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests;

use PHPUnit\Framework\TestCase;
use ReactphpX\Unbash\Printer;

use function ReactphpX\Unbash\parse;

final class PrinterTest extends TestCase
{
    public function testPrintIf(): void
    {
        $out = Printer::print(parse('if [ -f "$1" ]; then cat "$1"; fi'));
        $this->assertSame("if [ -f \"\$1\" ]; then\n  cat \"\$1\"\nfi", $out);
    }

    public function testPrintPipelineAndOr(): void
    {
        $this->assertSame('a | b && c || d', Printer::print(parse('a|b&&c||d')));
    }

    public function testPrintForLoop(): void
    {
        $out = Printer::print(parse('for i in 1 2 3; do echo $i; done'));
        $this->assertSame("for i in 1 2 3; do\n  echo \$i\ndone", $out);
    }

    public function testPrintBackground(): void
    {
        $this->assertSame('sleep 5 &', Printer::print(parse('sleep 5 &')));
    }

    public function testPrintPreservesShebang(): void
    {
        $out = Printer::print(parse("#!/bin/bash\necho hi"));
        $this->assertStringStartsWith("#!/bin/bash\n", $out);
    }
}
