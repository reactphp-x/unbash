<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests;

use PHPUnit\Framework\TestCase;

use function ReactphpX\Unbash\parse;

final class ErrorRecoveryTest extends TestCase
{
    public function testUnterminatedIfRecordsErrorAndReturnsPartialTree(): void
    {
        $ast = parse('if true; then echo hi');
        $this->assertNotEmpty($ast->errors);
        $this->assertSame("expected 'fi'", $ast->errors[0]->message);
        $this->assertSame('If', $ast->commands[0]->command->type);
    }

    public function testUnterminatedLoop(): void
    {
        $ast = parse('while true; do echo x');
        $this->assertNotEmpty($ast->errors);
        $this->assertSame('While', $ast->commands[0]->command->type);
    }

    public function testUnclosedSubshell(): void
    {
        $ast = parse('(echo hi');
        $this->assertNotEmpty($ast->errors);
        $this->assertSame('Subshell', $ast->commands[0]->command->type);
    }

    public function testNeverThrowsOnGarbage(): void
    {
        foreach (['((((', '${', '$(', '"unterminated', "'unterminated", '<<', 'case', '[[ '] as $garbage) {
            $ast = parse($garbage);
            $this->assertSame('Script', $ast->type);
        }
        $this->assertTrue(true);
    }

    public function testNestedScriptErrorsAreLocal(): void
    {
        // The root parses fine; the substitution body carries its own error.
        $ast = parse('echo $(if true; then)');
        $this->assertNull($ast->errors);
        $part = $ast->commands[0]->command->suffix[0]->parts[0];
        $this->assertNotEmpty($part->script->errors);
    }
}
