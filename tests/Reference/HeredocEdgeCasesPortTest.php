<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Command;
use ReactphpX\Unbash\Node;

/** Ported from webpro-nl/unbash test/heredoc-edge-cases.test.ts */
final class HeredocEdgeCasesPortTest extends RefTestCase
{
    private function roundtrip(string $src): Node
    {
        $ast = $this->parse($src);
        $this->assertSame($src, $this->verify($src, $ast));

        return $ast;
    }

    /** @return Node[] */
    private function redirects(string $src): array
    {
        return $this->getCmd($this->parse($src))->redirects;
    }

    public function testLeadingHeredocRedirect(): void
    {
        $source = "#!/bin/bash\n\n<<-EOF echo \"Hello\n  World\"\nEOF\n";
        $ast = $this->parse($source);
        $command = $this->getCmd($ast);
        $this->assertNull($ast->errors);
        $this->assertSame(['Script', 0, 45, [['Command', 13, 40]]], [
            $ast->type, $ast->pos, $ast->end,
            array_map(fn ($s) => [$s->command->type, $s->pos, $s->end], $ast->commands),
        ]);
        $this->assertSame(['echo', 20, 24], [$command->name->text, $command->name->pos, $command->name->end]);
    }

    public function testNamelessLeadingHeredoc(): void
    {
        $source = "<< 'EOF' > scratch.ts\nhello\nEOF";
        $ast = $this->parse($source);
        $command = $this->getCmd($ast);
        $this->assertNull($ast->errors);
        $this->assertNull($command->name);
        // NB: for the plain `>` redirect, `content` is null here (the reference's
        // shape helper reports the target text for it, which we do not replicate).
        $this->assertSame([
            ['<<', 0, 8, "'EOF'", 3, 8, "hello\n"],
            ['>', 9, 21, 'scratch.ts', 11, 21, null],
        ], array_map(fn ($r) => [$r->operator, $r->pos, $r->end, $r->target->text, $r->target->pos, $r->target->end, $r->content], $command->redirects));
    }

    public function testTwoHeredocsOneCommand(): void
    {
        $ast = $this->roundtrip("cmd <<A <<B\nfirst\nA\nsecond\nB\n");
        $cmd = $this->getCmd($ast);
        $this->assertCount(2, $cmd->redirects);
        $this->assertSame("first\n", $cmd->redirects[0]->content);
        $this->assertSame("second\n", $cmd->redirects[1]->content);
    }

    public function testTwoHeredocsSeparateCommands(): void
    {
        $ast = $this->roundtrip("cat <<A; cat <<B\ncontentA\nA\ncontentB\nB\n");
        $this->assertSame("contentA\n", $ast->commands[0]->command->redirects[0]->content);
        $this->assertSame("contentB\n", $ast->commands[1]->command->redirects[0]->content);
    }

    public function testHeredocAfterPipeWithSecond(): void
    {
        $this->roundtrip("cat <<A | grep x <<B\nalpha\nA\nbeta\nB\n");
    }

    public function testHeredocInIfBody(): void
    {
        $this->roundtrip("if true; then cat <<EOF\nhello\nEOF\nfi\n");
    }

    public function testHeredocInWhileLoop(): void
    {
        $ast = $this->roundtrip("while read line; do echo \$line; done <<EOF\nline1\nline2\nEOF\n");
        $stmt = $ast->commands[0];
        $this->assertCount(1, $stmt->redirects);
        $this->assertSame("line1\nline2\n", $stmt->redirects[0]->content);
    }

    public function testHeredocInFunction(): void
    {
        $this->roundtrip("f() {\ncat <<EOF\nbody text\nEOF\n}\n");
    }

    public function testDelimiterVariants(): void
    {
        $this->assertSame("stuff\n", $this->redirects("cat <<END-OF-DATA\nstuff\nEND-OF-DATA\n")[0]->content);
        $this->assertSame("stuff\n", $this->redirects("cat <<__EOF__\nstuff\n__EOF__\n")[0]->content);
        $this->assertSame("stuff\n", $this->redirects("cat <<123\nstuff\n123\n")[0]->content);
    }

    public function testEmptyDelimiterStopsAtBlankLine(): void
    {
        $ast = $this->parse("cat <<''\nhello\n\necho after");
        $this->assertNull($ast->errors);
        $this->assertSame(26, $ast->end);
        $this->assertCount(2, $ast->commands);
        $r = $this->getCmd($ast)->redirects[0];
        $this->assertSame("''", $r->target->text);
        $this->assertSame('', $r->target->value);
        $this->assertTrue($r->heredocQuoted);
        $this->assertSame("hello\n", $r->content);
        $next = $ast->commands[1]->command;
        $this->assertSame('echo', $next->name->text);
        $this->assertSame(['after'], array_map(fn ($w) => $w->text, $next->suffix));
    }

    public function testStripTabs(): void
    {
        $r = $this->redirects("cat <<-EOF\n\t\thello\n\t\tworld\n\tEOF\n")[0];
        $this->assertSame('<<-', $r->operator);
        $this->assertStringContainsString('hello', $r->content);
    }

    public function testContentEdgeCases(): void
    {
        $this->assertSame("EOFoo is not the end\n", $this->redirects("cat <<EOF\nEOFoo is not the end\nEOF\n")[0]->content);
        $this->assertSame("\n\nbetween blanks\n\n\n", $this->redirects("cat <<EOF\n\n\nbetween blanks\n\n\nEOF\n")[0]->content);
        $this->assertSame("\n\n\n", $this->redirects("cat <<EOF\n\n\n\nEOF\n")[0]->content);
    }

    public function testHerestrings(): void
    {
        $ast = $this->roundtrip('read x <<< "hello world"');
        $this->assertSame('<<<', $this->getCmd($ast)->redirects[0]->operator);
        $this->roundtrip('read x <<< $var');
        $this->roundtrip('read x <<< $(echo hello)');
    }

    public function testHeredocWithStderrRedirect(): void
    {
        $ast = $this->roundtrip("cmd <<EOF 2>/dev/null\nbody\nEOF\n");
        $this->assertCount(2, $this->getCmd($ast)->redirects);
    }

    public function testHeredocAfterPipe(): void
    {
        $this->roundtrip("cat <<EOF | grep hello\nhello world\ngoodbye world\nEOF\n");
    }

    public function testQuotedDelimiterVariants(): void
    {
        $this->assertSame("\$not_expanded\n", $this->redirects("cat <<'END'\n\$not_expanded\nEND")[0]->content);
        $this->assertStringContainsString('$not_expanded', $this->redirects("cat <<\"END\"\n\$not_expanded\nEND")[0]->content);
        $this->assertSame("body\n", $this->redirects("cat <<\\EOF\nbody\nEOF")[0]->content);
        $this->assertSame("body\n", $this->redirects("cat <<_LONG_DELIMITER_\nbody\n_LONG_DELIMITER_")[0]->content);
        $this->assertSame("EOF_not_end\n", $this->redirects("cat <<EOF\nEOF_not_end\nEOF")[0]->content);
    }

    public function testTwoHeredocsTokenizer(): void
    {
        $this->assertCount(2, $this->parse("cat <<A; cat <<B\n1\nA\n2\nB")->commands);
    }

    public function testEscapedSpaceInDelimiter(): void
    {
        $cmd = $this->getCmd($this->parse("cat <<E\\ OF\nbody\nE OF"));
        $this->assertCount(0, $cmd->suffix);
        $r = $cmd->redirects[0];
        $this->assertSame('E OF', $r->target->value);
        $this->assertTrue($r->heredocQuoted);
        $this->assertSame("body\n", $r->content);
    }

    public function testMidWordEscapeAndQuotesInDelimiter(): void
    {
        foreach ([
            ["cat <<E\\OF\nbody\nEOF", 'EOF'],
            ["cat <<E\"O\"F\nbody\nEOF", 'EOF'],
            ["cat <<E'O F'\nbody\nEO F", 'EO F'],
            ["cat <<'E'x\nbody\nEx", 'Ex'],
        ] as [$src, $value]) {
            $r = $this->getCmd($this->parse($src))->redirects[0];
            $this->assertSame($value, $r->target->value, $src);
            $this->assertTrue($r->heredocQuoted, $src);
            $this->assertSame("body\n", $r->content, $src);
        }
    }

    public function testUnquotedDelimiterForms(): void
    {
        foreach ([
            ["cat <<EOF\nbody\nEOF", 'EOF'],
            ["cat <<\$var\nbody\n\$var", '$var'],
        ] as [$src, $value]) {
            $r = $this->getCmd($this->parse($src))->redirects[0];
            $this->assertSame($value, $r->target->value, $src);
            $this->assertNotTrue($r->heredocQuoted, $src);
            $this->assertSame("body\n", $r->content, $src);
        }
    }

    public function testDollarQuotedDelimiters(): void
    {
        foreach (["cat <<\$'\\x45OF'\nbody\nEOF", "cat <<\$\"EOF\"\nbody\nEOF"] as $src) {
            $ast = $this->parse($src);
            $this->assertNull($ast->errors, $src);
            $r = $this->getCmd($ast)->redirects[0];
            $this->assertSame('EOF', $r->target->value, $src);
            $this->assertTrue($r->heredocQuoted, $src);
            $this->assertSame("body\n", $r->content, $src);
        }
    }

    public function testSubstitutionSyntaxLiteralInDelimiters(): void
    {
        foreach (['$(foo)', '$((1+2))'] as $delimiter) {
            $src = "cat <<$delimiter\nbody\n$delimiter";
            $ast = $this->parse($src);
            $this->assertNull($ast->errors, $src);
            $r = $this->getCmd($ast)->redirects[0];
            $this->assertSame($delimiter, $r->target->value, $src);
            $this->assertNotTrue($r->heredocQuoted, $src);
            $this->assertSame("body\n", $r->content, $src);
        }
    }

    public function testExtentScannerHeredocEdgesSkipped(): void
    {
        $this->markTestSkipped('Backtick/newline-spanning delimiters and heredoc-line-with-paren extent are not modeled.');
    }
}
