<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

/** Ported from webpro-nl/unbash test/error-recovery.test.ts */
final class ErrorRecoveryPortTest extends RefTestCase
{
    private function hasError(\ReactphpX\Unbash\Node $ast, string $substr): bool
    {
        foreach ($ast->errors ?? [] as $e) {
            if (str_contains($e->message, $substr)) {
                return true;
            }
        }

        return false;
    }

    public function testUnclosedDoNotThrow(): void
    {
        foreach ([
            "echo 'unterminated", 'echo "unterminated', '(echo hello',
            'if true; then echo yes', 'if a; then b; elif c; then d',
            'for x in a b; do echo $x', 'while true; do echo loop',
        ] as $src) {
            $this->assertSame('Script', $this->parse($src)->type, $src);
        }
    }

    public function testMissingFiCollectsError(): void
    {
        $ast = $this->parse('if true; then echo yes');
        $this->assertNotNull($ast->errors);
        $this->assertTrue($this->hasError($ast, "expected 'fi'"));
    }

    public function testMissingDoneCollectsError(): void
    {
        $this->assertTrue($this->hasError($this->parse('for x in a b; do echo $x'), "expected 'done'"));
    }

    public function testMissingParenCollectsError(): void
    {
        $this->assertTrue($this->hasError($this->parse('(echo hello'), "expected ')'"));
    }

    public function testUnclosedSingleQuote(): void
    {
        $this->assertTrue($this->hasError($this->parse("echo 'unterminated"), 'unterminated single quote'));
    }

    public function testUnclosedDoubleQuote(): void
    {
        $this->assertTrue($this->hasError($this->parse('echo "unterminated'), 'unterminated double quote'));
    }

    public function testUnclosedDoubleQuoteSlicesLiteral(): void
    {
        $ast = $this->parse('echo "abc');
        $word = $this->getCmd($ast)->suffix[0];
        $this->assertSame('abc', $word->value);
        $this->assertSame('DoubleQuoted', $word->parts[0]->type);
        $this->assertEquals([['type' => 'Literal', 'value' => 'abc', 'text' => 'abc']], array_map([$this, 'toArray'], $word->parts[0]->parts));
        $this->assertTrue($this->hasError($ast, 'unterminated double quote'));
    }

    public function testUnclosedLocaleString(): void
    {
        $ast = $this->parse('echo $"abc');
        $word = $this->getCmd($ast)->suffix[0];
        $this->assertSame('abc', $word->value);
        $this->assertSame('LocaleString', $word->parts[0]->type);
        $this->assertEquals([['type' => 'Literal', 'value' => 'abc', 'text' => 'abc']], array_map([$this, 'toArray'], $word->parts[0]->parts));
        $this->assertTrue($this->hasError($ast, 'unterminated double quote'));
    }

    public function testUnclosedDoubleQuoteTrailingLiteral(): void
    {
        $ast = $this->parse('echo "pre$(x) ab');
        $word = $this->getCmd($ast)->suffix[0];
        $this->assertSame('pre$(x) ab', $word->value);
        $quoted = $word->parts[0];
        $this->assertSame('DoubleQuoted', $quoted->type);
        $this->assertCount(3, $quoted->parts);
        $this->assertEquals(['type' => 'Literal', 'value' => ' ab', 'text' => ' ab'], $this->toArray($quoted->parts[2]));
        $this->assertTrue($this->hasError($ast, 'unterminated double quote'));
    }

    public function testUnclosedAnsiCQuote(): void
    {
        $plain = $this->parse("echo \$'abc");
        $this->assertSame('abc', $this->getCmd($plain)->suffix[0]->value);
        $this->assertEquals([['message' => 'unterminated ANSI-C quote', 'pos' => 6]], $this->errorsArr($plain));

        $trailing = $this->parse("echo \$'\\");
        $this->assertSame("\$'\\", $this->getCmd($trailing)->suffix[0]->text);
        $this->assertSame('\\', $this->getCmd($trailing)->suffix[0]->value);
        $this->assertEquals([['message' => 'unterminated ANSI-C quote', 'pos' => 6]], $this->errorsArr($trailing));
    }

    public function testUnclosedAnsiCInsideParamExpansion(): void
    {
        $ast = $this->parse("echo \${x:-\$'abc}");
        $this->assertEquals([
            ['message' => 'unterminated parameter expansion', 'pos' => 5],
            ['message' => 'unterminated ANSI-C quote', 'pos' => 11],
        ], $this->errorsArr($ast));
    }

    public function testValidInputNoErrors(): void
    {
        $this->assertNull($this->parse('echo hello world')->errors);
        $this->assertNull($this->parse('if true; then echo yes; fi')->errors);
    }

    public function testCommentOnlyThenBody(): void
    {
        $source = "if [[ \"\${tmp}\" = *\"-----BEGIN CERTIFICATE-----\"* ]]; then\n  # ...\nfi";
        $this->assertEquals([['message' => "expected command after 'then'", 'pos' => 66]], $this->errorsArr($this->parse($source)));

        $valid = "if [[ \"\${tmp}\" = *\"-----BEGIN CERTIFICATE-----\"* ]]; then\n  # ...\n  :\nfi";
        $this->assertNull($this->parse($valid)->errors);
    }

    public function testUnexpectedRootTerminators(): void
    {
        foreach (['then', 'else', 'elif', 'fi', 'do', 'done', 'in', 'esac', ')', '}', ';;', ';&', ';;&'] as $terminator) {
            $source = "safe\n$terminator; recovered";
            $ast = $this->parse($source);
            $this->assertEquals([['message' => "unexpected token '$terminator'", 'pos' => strpos($source, $terminator)]], $this->errorsArr($ast), $source);
            $this->assertCount(2, $ast->commands, $source);
            $this->assertSame('safe', $ast->commands[0]->command->name->value, $source);
            $this->assertSame('recovered', $ast->commands[1]->command->name->value, $source);
        }
    }

    public function testValidTrailingSeparators(): void
    {
        foreach (['safe;', 'safe &', "safe\n", "safe; # comment\n\n\t# final comment"] as $source) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertCount(1, $ast->commands, $source);
        }
    }

    public function testRootRecoveryLargeStatementList(): void
    {
        $count = 5000;
        $ast = $this->parse("fi;\n" . str_repeat("x\n", $count));
        $this->assertEquals([['message' => "unexpected token 'fi'", 'pos' => 0]], $this->errorsArr($ast));
        $this->assertCount($count, $ast->commands);
    }

    public function testTrailingOperators(): void
    {
        foreach (['&&', '||', '|', '|&'] as $operator) {
            $source = "echo $operator";
            $this->assertEquals([['message' => "expected command after '$operator'", 'pos' => strlen($source)]], $this->errorsArr($this->parse($source)), $source);
        }
    }

    public function testOperatorsAcceptCommandAfterNewline(): void
    {
        foreach (['&&', '||', '|', '|&'] as $operator) {
            $this->assertNull($this->parse("echo $operator\nprintf next")->errors, $operator);
        }
    }

    public function testMissingRedirectTargets(): void
    {
        foreach ([
            'echo >', 'echo >>', 'echo <', 'cat <<', 'cat <<-', 'echo <<<', 'echo <>',
            'echo <&', 'echo >&', 'echo >|', 'echo &>', 'echo &>>', 'echo 2>', 'echo {fd}>',
        ] as $source) {
            $this->assertEquals([['message' => 'expected redirect target', 'pos' => strlen($source)]], $this->errorsArr($this->parse($source)), $source);
        }
    }

    public function testQuotedEmptyRedirectTargets(): void
    {
        foreach (['echo >""', "echo >''", 'echo <<< ""', "cat <<''"] as $source) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $target = $this->getCmd($ast)->redirects[0]->target;
            $this->assertNotNull($target, $source);
            $this->assertSame('', $target->value, $source);
        }
    }

    public function testCommentsAreNotRedirectTargets(): void
    {
        foreach (['echo >#comment', 'echo > #comment', 'echo &>#comment', 'echo <<<#comment', 'cat <<#comment'] as $source) {
            $this->assertEquals([['message' => 'expected redirect target', 'pos' => strpos($source, '#')]], $this->errorsArr($this->parse($source)), $source);
        }
    }

    public function testMultipleErrors(): void
    {
        $ast = $this->parse('if true; then (echo hello');
        $this->assertNotNull($ast->errors);
        $this->assertGreaterThanOrEqual(2, count($ast->errors));
    }

    public function testErrorPositionsReasonable(): void
    {
        $input = 'for x in a b; do echo $x';
        $ast = $this->parse($input);
        $this->assertNotNull($ast->errors);
        foreach ($ast->errors as $err) {
            $this->assertGreaterThanOrEqual(0, $err->pos);
            $this->assertLessThanOrEqual(strlen($input), $err->pos);
        }
    }

    public function testEmptyWhitespaceCommentOnly(): void
    {
        $this->assertCount(0, $this->parse('')->commands);
        $this->assertCount(0, $this->parse("   \n\n  \t  ")->commands);
        $this->assertCount(0, $this->parse("# just a comment\n# another")->commands);
    }

    public function testUnclosedCommandSubstitution(): void
    {
        $this->assertTrue($this->hasError($this->parse('curl $(foo'), 'unterminated command substitution'));
        $this->assertTrue($this->hasError($this->parse('echo "$(foo"'), 'unterminated command substitution'));
    }

    public function testUnclosedProcessSubstitution(): void
    {
        $this->assertTrue($this->hasError($this->parse('diff <(foo'), 'unterminated process substitution'));
    }

    public function testUnclosedParameterExpansion(): void
    {
        $ast = $this->parse('echo ${');
        $this->assertSame('${', $this->getCmd($ast)->suffix[0]->text);
        $this->assertEquals([['message' => 'unterminated parameter expansion', 'pos' => 5]], $this->errorsArr($ast));
    }

    public function testUnclosedParameterExpansionPartialStructure(): void
    {
        $source = 'echo pre${name';
        $ast = $this->parse($source);
        $word = $this->getCmd($ast)->suffix[0];
        $this->assertSame('pre${name', $word->text);
        $this->assertSame(['Literal', 'ParameterExpansion'], array_map(fn ($p) => $p->type, $word->parts));
        $this->assertSame('name', $word->parts[1]->parameter);
        $this->assertEquals([['message' => 'unterminated parameter expansion', 'pos' => strpos($source, '$')]], $this->errorsArr($ast));
    }

    public function testClosedEmptyParameterExpansions(): void
    {
        $this->assertNull($this->parse('echo ${name}')->errors);
        $this->assertNull($this->parse('echo ${}')->errors);
    }

    public function testUnclosedCommandSubstitutionKeepsInnerName(): void
    {
        $ast = $this->parse('curl $(foo');
        $word = $this->getCmd($ast)->suffix[0];
        $part = null;
        foreach ($word->parts as $p) {
            if ($p->type === 'CommandExpansion') {
                $part = $p;
            }
        }
        $this->assertSame('foo', $part->script->commands[0]->command->name->text);
    }

    public function testErrorsOrderedByPosition(): void
    {
        $ast = $this->parse("for i 'in' a; do echo \$i; done");
        $positions = array_map(fn ($e) => $e->pos, $ast->errors);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        $mixed = $this->parse("safe\nfi; echo ok\n;;\necho done");
        $mixedPositions = array_map(fn ($e) => $e->pos, $mixed->errors);
        $mixedSorted = $mixedPositions;
        sort($mixedSorted);
        $this->assertSame($mixedSorted, $mixedPositions);
    }
}
