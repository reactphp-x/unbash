<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

/** Ported from webpro-nl/unbash test/redirects.test.ts */
final class RedirectsPortTest extends RefTestCase
{
    /** @return Node[]|null */
    private function wp(Word $w): ?array
    {
        return $w->parts;
    }

    public function testSimpleOut(): void
    {
        $c = $this->getCmd($this->parse('echo hello > out.txt'));
        $this->assertSame('echo', $c->name->text);
        $this->assertSame(['hello'], array_map(fn ($s) => $s->text, $c->suffix));
        $this->assertCount(1, $c->redirects);
        $this->assertSame('>', $c->redirects[0]->operator);
        $this->assertSame('out.txt', $c->redirects[0]->target->text);
    }

    public function testAppend(): void
    {
        $c = $this->getCmd($this->parse('echo x >> log'));
        $this->assertCount(1, $c->redirects);
        $this->assertSame('>>', $c->redirects[0]->operator);
        $this->assertSame('log', $c->redirects[0]->target->text);
    }

    public function testInput(): void
    {
        $c = $this->getCmd($this->parse('sort < data.txt'));
        $this->assertSame('<', $c->redirects[0]->operator);
        $this->assertSame('data.txt', $c->redirects[0]->target->text);
    }

    public function testMultipleRedirects(): void
    {
        $c = $this->getCmd($this->parse('cmd < in.txt > out.txt 2>&1'));
        $this->assertCount(3, $c->redirects);
        $this->assertSame('<', $c->redirects[0]->operator);
        $this->assertSame('>', $c->redirects[1]->operator);
        $this->assertSame('>&', $c->redirects[2]->operator);
    }

    public function testSingleBracketAroundRedirect(): void
    {
        $source = "[ 2 -lt 3 ]\necho >1\n[ ]\n";
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $this->assertSame([
            ['[', 0, 11],
            ['echo', 12, 19],
            ['[', 20, 23],
        ], array_map(fn ($s) => [$s->command->name?->text, $s->command->pos, $s->command->end], $ast->commands));
        $r = $this->getCmd($ast, 1)->redirects[0];
        $this->assertSame(['>', 17, 19, '1', 18, 19], [$r->operator, $r->pos, $r->end, $r->target->text, $r->target->pos, $r->target->end]);
    }

    public function testMissingTargetNoWordReuse(): void
    {
        $this->assertNull($this->getCmd($this->parse('echo >'))->redirects[0]->target);
    }

    public function testRedirectOnlyCommand(): void
    {
        $c = $this->getCmd($this->parse('< input.txt'));
        $this->assertNull($c->name);
        $this->assertCount(0, $c->prefix);
        $this->assertSame('<', $c->redirects[0]->operator);
        $this->assertSame('input.txt', $c->redirects[0]->target->text);
        $t = $this->getCmd($this->parse('> out.txt'));
        $this->assertSame('>', $t->redirects[0]->operator);
        $this->assertSame('out.txt', $t->redirects[0]->target->text);
    }

    public function testEscapedQuotedHashRemainsTarget(): void
    {
        foreach (['echo >\#file', "echo >'#file'", 'echo >"#file"'] as $source) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame('#file', $this->getCmd($ast)->redirects[0]->target->value, $source);
        }
    }

    public function testAmpGt(): void
    {
        $c = $this->getCmd($this->parse('cmd &> /dev/null'));
        $this->assertSame('&>', $c->redirects[0]->operator);
        $this->assertSame('/dev/null', $c->redirects[0]->target->text);
    }

    public function testAmpGtDoesNotBackground(): void
    {
        $ast = $this->parse('cmd &> /dev/null');
        $this->assertCount(1, $ast->commands);
        $this->assertSame('cmd', $this->getCmd($ast)->name->text);
    }

    public function testAmpGtGtDoesNotBackground(): void
    {
        $ast = $this->parse('cmd &>> log');
        $this->assertCount(1, $ast->commands);
    }

    public function testFdDup(): void
    {
        $c = $this->getCmd($this->parse('cmd 2>&1'));
        $this->assertSame('>&', $c->redirects[0]->operator);
        $this->assertSame(2, $c->redirects[0]->fileDescriptor);
    }

    public function testVarnameOut(): void
    {
        $c = $this->getCmd($this->parse('cmd {fd}>out.txt'));
        $this->assertSame('>', $c->redirects[0]->operator);
        $this->assertSame('fd', $c->redirects[0]->variableName);
        $this->assertSame('out.txt', $c->redirects[0]->target->text);
    }

    public function testVarnameIn(): void
    {
        $c = $this->getCmd($this->parse('cmd {myfd}<input.txt'));
        $this->assertSame('<', $c->redirects[0]->operator);
        $this->assertSame('myfd', $c->redirects[0]->variableName);
    }

    public function testVarnameAppend(): void
    {
        $c = $this->getCmd($this->parse('cmd {fd}>>log.txt'));
        $this->assertSame('>>', $c->redirects[0]->operator);
        $this->assertSame('fd', $c->redirects[0]->variableName);
    }

    public function testVarnameClose(): void
    {
        $c = $this->getCmd($this->parse('cmd {fd}>&-'));
        $this->assertSame('>&', $c->redirects[0]->operator);
        $this->assertSame('fd', $c->redirects[0]->variableName);
    }

    public function testMultipleFdRedirects(): void
    {
        $c = $this->getCmd($this->parse('foo >&2 <&0 2>file'));
        $this->assertGreaterThanOrEqual(3, count($c->redirects));
    }

    public function testExecCloseFds(): void
    {
        $this->assertGreaterThan(0, count($this->parse('exec <&- >&-')->commands));
    }

    public function testHeredocBodyNotInSuffix(): void
    {
        $c = $this->getCmd($this->parse("cat <<EOF\nbody\nEOF"));
        $this->assertSame('cat', $c->name->text);
        $this->assertCount(0, $c->suffix);
    }

    public function testHeredocCapturedWithBody(): void
    {
        $c = $this->getCmd($this->parse("cat << EOF\nhello\nworld\nEOF"));
        $this->assertCount(1, $c->redirects);
        $this->assertSame('<<', $c->redirects[0]->operator);
        $this->assertSame('EOF', $c->redirects[0]->target->text);
        $this->assertSame("hello\nworld\n", $c->redirects[0]->content);
    }

    public function testHeredocStrip(): void
    {
        $c = $this->getCmd($this->parse("cat <<-END\n\tindented\nEND"));
        $this->assertSame('<<-', $c->redirects[0]->operator);
        $this->assertSame("\tindented\n", $c->redirects[0]->content);
    }

    public function testHeredocEmptyDelimiter(): void
    {
        $c = $this->getCmd($this->parse("cat <<\"\"\nhello\n"));
        $this->assertSame('""', $c->redirects[0]->target->text);
        $this->assertSame('', $c->redirects[0]->target->value);
        $this->assertSame("hello\n", $c->redirects[0]->content);
    }

    public function testQuotedHeredocDelimiterPreservesRaw(): void
    {
        $source = "cat <<'EOF'\n\$name\nEOF";
        $r = $this->getCmd($this->parse($source))->redirects[0];
        $this->assertSame("'EOF'", $r->target->text);
        $this->assertSame('EOF', $r->target->value);
        $this->assertSame($r->target->text, substr($source, $r->target->pos, $r->target->end - $r->target->pos));
        $this->assertTrue($r->heredocQuoted);
    }

    public function testHeredocVariants(): void
    {
        foreach ([
            "cat << 'EOF'\na=\$b\nEOF",
            "cat <<EOF > \$tmpfile\nhello\nEOF",
            "one <<EOF | grep two\nthree\nEOF",
            "cat <<-_EOF_ || die \"failed\"\n\techo hello\n_EOF_",
            "cat <<EOF |\n1\n2\n3\nEOF\ntac",
            "cat <<OUTER\nOuter\n\$(cat <<INNER\nInner\nINNER)\nOUTER",
            "while cat <<E1; do cat <<E2; break; done\n1\nE1\n2\nE2",
        ] as $source) {
            $this->assertGreaterThan(0, count($this->parse($source)->commands), $source);
        }
    }

    public function testHerestring(): void
    {
        $c = $this->getCmd($this->parse('cmd <<< value'));
        $this->assertSame('cmd', $c->name->text);
        $this->assertCount(0, $c->suffix);
        $this->assertSame('<<<', $c->redirects[0]->operator);
        $this->assertSame('value', $c->redirects[0]->target->text);
    }

    public function testMultiDigitFdHerestring(): void
    {
        $r = $this->getCmd($this->parse('cat /dev/fd/10 10<<<"test"'))->redirects[0];
        $this->assertSame([10, '<<<', '"test"'], [$r->fileDescriptor, $r->operator, $r->target->text]);
    }

    public function testRedirectOrderingAndRanges(): void
    {
        $cases = [
            ['cat >x <<< \'x\'', 'cat', [], [['>', 'x', 4, 6, 5, 6], ['<<<', "'x'", 7, 14, 11, 14]]],
            ['x <<<x x >x', 'x', ['x'], [['<<<', 'x', 2, 6, 5, 6], ['>', 'x', 9, 11, 10, 11]]],
            ['x <x a b c', 'x', ['a', 'b', 'c'], [['<', 'x', 2, 4, 3, 4]]],
            ['rev > output <<< hello', 'rev', [], [['>', 'output', 4, 12, 6, 12], ['<<<', 'hello', 13, 22, 17, 22]]],
            ['rev <<< hello > output', 'rev', [], [['<<<', 'hello', 4, 13, 8, 13], ['>', 'output', 14, 22, 16, 22]]],
        ];
        foreach ($cases as [$source, $name, $suffix, $redirects]) {
            $ast = $this->parse($source);
            $command = $this->getCmd($ast);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($name, $command->name->text, $source);
            $this->assertSame($suffix, array_map(fn ($w) => $w->text, $command->suffix), $source);
            $this->assertSame($redirects, array_map(
                fn ($r) => [$r->operator, $r->target?->text, $r->pos, $r->end, $r->target?->pos, $r->target?->end],
                $command->redirects
            ), $source);
        }
    }

    public function testHerestringDoubleQuotedVar(): void
    {
        $this->assertGreaterThan(0, count($this->parse('cat <<<"$ENTRIES"')->commands));
    }

    public function testHerestringBeforeCommandName(): void
    {
        $this->assertGreaterThan(0, count($this->parse('<<<string cmd arg')->commands));
    }

    public function testHerestringComplexQuoting(): void
    {
        $this->assertGreaterThan(0, count($this->parse('caddy run --config - <<< \'{"apps":{"http":{"servers":{"srv0":{"listen":[":8003"]}}}}}\'')->commands));
    }

    public function testBraceGroupWithRedirect(): void
    {
        $stmt = $this->parse('{ echo a; } >&2')->commands[0];
        $this->assertSame('BraceGroup', $stmt->command->type);
        $this->assertCount(1, $stmt->redirects);
        $this->assertSame('>&', $stmt->redirects[0]->operator);
    }

    public function testSubshellWithRedirect(): void
    {
        $stmt = $this->parse('(cmd1) > out.txt')->commands[0];
        $this->assertSame('Subshell', $stmt->command->type);
        $this->assertCount(1, $stmt->redirects);
        $this->assertSame('>', $stmt->redirects[0]->operator);
    }

    public function testFunctionWithRedirect(): void
    {
        $fn = $this->parse('function f { echo ok; } 2>&1')->commands[0]->command;
        $this->assertSame('Function', $fn->type);
        $this->assertCount(1, $fn->redirects);
    }

    public function testWhileLoopWithInputRedirect(): void
    {
        $ast = $this->parse("while IFS= read -r line; do\n    echo \"\$line\"\ndone < input.txt");
        $stmt = $ast->commands[0];
        $this->assertSame('While', $stmt->command->type);
        $this->assertCount(1, $stmt->redirects);
    }

    public function testTargetPartsVariable(): void
    {
        $c = $this->getCmd($this->parse('echo hello > $outfile'));
        $this->assertSame('$outfile', $c->redirects[0]->target->text);
        $this->assertSame('SimpleExpansion', $this->wp($c->redirects[0]->target)[0]->type);
    }

    public function testTargetPartsParamExpansion(): void
    {
        $c = $this->getCmd($this->parse('echo hello > ${dir}/out.txt'));
        $this->assertSame('ParameterExpansion', $this->wp($c->redirects[0]->target)[0]->type);
    }

    public function testTargetPartsCommandSubstitution(): void
    {
        $c = $this->getCmd($this->parse('echo hello > $(mktemp)'));
        $this->assertSame('CommandExpansion', $this->wp($c->redirects[0]->target)[0]->type);
        $this->assertNotNull($this->wp($c->redirects[0]->target)[0]->script);
    }

    public function testTargetPartsQuoted(): void
    {
        $c = $this->getCmd($this->parse('echo hello > "out file.txt"'));
        $this->assertSame('DoubleQuoted', $this->wp($c->redirects[0]->target)[0]->type);
    }

    public function testTargetPreservesRawText(): void
    {
        $source = 'echo > "file name"';
        $target = $this->getCmd($this->parse($source))->redirects[0]->target;
        $this->assertSame('"file name"', $target->text);
        $this->assertSame('file name', $target->value);
        $this->assertSame($target->text, substr($source, $target->pos, $target->end - $target->pos));
    }

    public function testHerestringTargetParts(): void
    {
        $c = $this->getCmd($this->parse('cmd <<< "$value"'));
        $this->assertSame('<<<', $c->redirects[0]->operator);
        $this->assertSame('DoubleQuoted', $this->wp($c->redirects[0]->target)[0]->type);
    }

    public function testAmpGtTargetParts(): void
    {
        $c = $this->getCmd($this->parse('cmd &> $logfile'));
        $this->assertSame('&>', $c->redirects[0]->operator);
        $this->assertSame('SimpleExpansion', $this->wp($c->redirects[0]->target)[0]->type);
    }

    public function testPlainTargetHasNoParts(): void
    {
        $c = $this->getCmd($this->parse('echo hello > out.txt'));
        $this->assertSame('out.txt', $c->redirects[0]->target->text);
        $this->assertNull($this->wp($c->redirects[0]->target));
    }

    public function testDigitBeforeGtIsFd(): void
    {
        $c = $this->getCmd($this->parse('echo 2>/dev/null'));
        $this->assertSame(2, $c->redirects[0]->fileDescriptor);
        $this->assertSame('>', $c->redirects[0]->operator);
    }

    public function testDigitBeforeLtIsFd(): void
    {
        $c = $this->getCmd($this->parse('cmd 0<input'));
        $this->assertSame(0, $c->redirects[0]->fileDescriptor);
        $this->assertSame('<', $c->redirects[0]->operator);
    }

    public function testMultiDigitFd(): void
    {
        $c = $this->getCmd($this->parse('cmd 10>file'));
        $this->assertSame(10, $c->redirects[0]->fileDescriptor);
    }

    public function testTrailingBackslashLiteralTarget(): void
    {
        $command = $this->parse('>\\')->commands[0]->command;
        $this->assertCount(1, $command->redirects);
        $this->assertSame('>', $command->redirects[0]->operator);
        $this->assertSame('\\', $command->redirects[0]->target->text);
        $this->assertNull($this->parse('>\\')->errors);
        $read = $this->parse('cat <a\\')->commands[0]->command;
        $this->assertSame('a\\', $read->redirects[0]->target->text);
        $this->assertSame('a\\', $this->parse('echo a\\')->commands[0]->command->suffix[0]->text);
        $this->assertSame('x\\', $this->parse('cat <<x\\')->commands[0]->command->redirects[0]->target->text);
    }
}
