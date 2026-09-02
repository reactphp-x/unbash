<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

/** Ported from webpro-nl/unbash test/quoting.test.ts */
final class QuotingPortTest extends RefTestCase
{
    public function testSingleQuotes(): void
    {
        $this->assertSame("'hello world'", $this->getCmd($this->parse("echo 'hello world'"))->suffix[0]->text);
    }

    public function testDoubleQuotes(): void
    {
        $this->assertSame('"hello world"', $this->getCmd($this->parse('echo "hello world"'))->suffix[0]->text);
    }

    public function testEscapedCharsInDoubleQuotes(): void
    {
        $this->assertSame('"hello \"world\""', $this->getCmd($this->parse('echo "hello \"world\""'))->suffix[0]->text);
    }

    public function testQuotesMidWord(): void
    {
        $this->assertSame("ec'h'o", $this->getCmd($this->parse("ec'h'o hello"))->name->text);
    }

    public function testDoubleQuotesMidWord(): void
    {
        $this->assertSame('ec"h"o', $this->getCmd($this->parse('ec"h"o hello'))->name->text);
    }

    public function testAdjacentQuotedSegments(): void
    {
        $this->assertSame("'foo'\"bar\"baz", $this->getCmd($this->parse("echo 'foo'\"bar\"baz"))->suffix[0]->text);
    }

    public function testDoubleQuotedReservedWordNotKeyword(): void
    {
        $c = $this->getCmd($this->parse('"if" true'));
        $this->assertSame('"if"', $c->name->text);
        $this->assertSame('true', $c->suffix[0]->text);
    }

    public function testSingleQuotedReservedWordNotKeyword(): void
    {
        $this->assertSame("'if'", $this->getCmd($this->parse("'if' true"))->name->text);
    }

    public function testPartiallyQuotedReservedWordNotKeyword(): void
    {
        $this->assertSame('i"f"', $this->getCmd($this->parse('i"f" true'))->name->text);
    }

    public function testBackslashEscapedReservedWordNotKeyword(): void
    {
        $this->assertSame('\if', $this->getCmd($this->parse('\if true'))->name->text);
    }

    public function testDollarQuotedReservedWordsNotKeywords(): void
    {
        foreach (["\$'if' true", '$"if" true', "i\$''f true"] as $source) {
            $ast = $this->parse($source);
            $c = $this->getCmd($ast);
            $this->assertSame('if', $c->name->value, $source);
            $this->assertSame('true', $c->suffix[0]->value, $source);
            $this->assertNull($ast->errors, $source);
        }
    }

    public function testSingleQuoteInsideDoubleQuotesIsLiteral(): void
    {
        $this->assertSame('"TEST1 \'TEST2"', $this->getCmd($this->parse('echo "TEST1 \'TEST2"'))->suffix[0]->text);
    }

    public function testDollarQuoteInsideDoubleQuotesIsLiteral(): void
    {
        $cases = [
            ['echo "a$\'b"', 'a$\'b'],
            ['echo "x$\'y\'z"', 'x$\'y\'z'],
            ['echo "$\'"', '$\''],
            ['echo "grep -E \'^(a|b)$\' || true"', 'grep -E \'^(a|b)$\' || true'],
        ];
        foreach ($cases as [$source, $value]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($value, $this->getCmd($ast)->suffix[0]->value, $source);
        }
    }

    public function testAnsiCDecodesOutsideDoubleQuotes(): void
    {
        $ast = $this->parse("echo \$'a\\tb'");
        $this->assertNull($ast->errors);
        $this->assertSame("a\tb", $this->getCmd($ast)->suffix[0]->value);
    }

    public function testDoubleQuoteInsideSingleQuotesIsLiteral(): void
    {
        $this->assertSame("'TEST1 \"TEST2'", $this->getCmd($this->parse("echo 'TEST1 \"TEST2'"))->suffix[0]->text);
    }

    public function testEscapedQuotesInUnquotedContext(): void
    {
        $this->assertSame("ec\\'\\\"ho", $this->getCmd($this->parse("ec\\'\\\"ho"))->name->text);
    }

    public function testEscapedBackslashBeforeClosingDoubleQuote(): void
    {
        $this->assertSame('"foo\\\\"', $this->getCmd($this->parse('echo "foo\\\\"'))->suffix[0]->text);
    }

    public function testBackslashInDoubleQuotesOnlyEscapesSpecial(): void
    {
        $this->assertSame('"foo\a"', $this->getCmd($this->parse('echo "foo\a"'))->suffix[0]->text);
    }

    public function testEscapedDollarPreventsExpansion(): void
    {
        $this->assertSame('"\$ciao"', $this->getCmd($this->parse('echo "\$ciao"'))->suffix[0]->text);
    }

    public function testPartiallyQuotedWordsJoin(): void
    {
        $this->assertSame("TEST1' TEST2 'TEST3", $this->getCmd($this->parse("echo TEST1' TEST2 'TEST3"))->suffix[0]->text);
    }

    public function testEmptyQuotesAndCloseEscapeReopen(): void
    {
        $source = <<<'EOT'
        echo '\' '\' 'a'\''b' '' "" ''a''
        EOT;
        $words = $this->getCmd($this->parse($source))->suffix;
        $this->assertSame(['\\', '\\', "a'b", '', '', 'a'], array_map(fn ($w) => $w->value, $words));
        $this->assertSame(["'\\'", "'\\'", "'a'\\''b'", "''", '""', "''a''"], array_map(fn ($w) => $w->text, $words));
        $this->assertSame(['SingleQuoted', 'Literal', 'SingleQuoted'], array_map(fn ($p) => $p->type, $words[2]->parts));
    }

    public function testBackslashesDoNotEscapeQuotesInsideSingleQuotes(): void
    {
        $invalid = $this->parse(<<<'EOT'
        'sed -E \'s///\''
        EOT);
        $this->assertEquals([['message' => 'unterminated single quote', 'pos' => 16]], $this->errorsArr($invalid));

        $valid = $this->parse(<<<'EOT'
        'sed -E '\''s///'\'
        EOT);
        $word = $this->getCmd($valid)->name;
        $this->assertNull($valid->errors);
        $this->assertSame("sed -E 's///'", $word->value);
        $this->assertEquals([
            ['type' => 'SingleQuoted', 'value' => 'sed -E ', 'text' => "'sed -E '"],
            ['type' => 'Literal', 'value' => "'", 'text' => "\\'"],
            ['type' => 'SingleQuoted', 'value' => 's///', 'text' => "'s///'"],
            ['type' => 'Literal', 'value' => "'", 'text' => "\\'"],
        ], $this->partsArr($word));
    }

    public function testLocaleStringsStructuredWhenConcatenated(): void
    {
        $ast = $this->parse('foo$"bar"');
        $word = $this->getCmd($ast)->name;
        $this->assertNull($ast->errors);
        $this->assertSame(['foo$"bar"', 'foobar', 0, 9], [$word->text, $word->value, $word->pos, $word->end]);
        $this->assertEquals([
            ['type' => 'Literal', 'value' => 'foo', 'text' => 'foo'],
            ['type' => 'LocaleString', 'text' => '$"bar"', 'parts' => [
                ['type' => 'Literal', 'value' => 'bar', 'text' => 'bar'],
            ]],
        ], $this->partsArr($word));
    }

    public function testUnquotedEscapesSuppressExpansion(): void
    {
        $words = $this->getCmd($this->parse('echo ab\${x}def bo\`op'))->suffix;
        $this->assertSame(['ab${x}def', 'bo`op'], array_map(fn ($w) => $w->value, $words));
        $this->assertNull($words[0]->parts);
        $this->assertNull($words[1]->parts);
    }

    public function testDollarBeforeClosingDoubleQuoteIsLiteral(): void
    {
        $ast = $this->parse('grep "xy$"');
        $word = $this->getCmd($ast)->suffix[0];
        $this->assertSame('"xy$"', $word->text);
        $this->assertSame('xy$', $word->value);
        $this->assertEquals([
            ['type' => 'DoubleQuoted', 'text' => '"xy$"', 'parts' => [
                ['type' => 'Literal', 'value' => 'xy$', 'text' => 'xy$'],
            ]],
        ], $this->partsArr($word));
        $this->assertNull($ast->errors);
    }

    // ── $'...' ANSI-C quoting ─────────────────────────────────────────

    public function testAnsiCTexts(): void
    {
        $this->assertSame("\$'hello\\nworld'", $this->getCmd($this->parse("echo \$'hello\\nworld'"))->suffix[0]->text);
        $this->assertSame("\$'a\\tb'", $this->getCmd($this->parse("echo \$'a\\tb'"))->suffix[0]->text);
        $this->assertSame("\$'it\\'s'", $this->getCmd($this->parse("echo \$'it\\'s'"))->suffix[0]->text);
        $this->assertSame("\$'\\\\'", $this->getCmd($this->parse("echo \$'\\\\'"))->suffix[0]->text);
        $this->assertSame("\$'\\e[31m'", $this->getCmd($this->parse("echo \$'\\e[31m'"))->suffix[0]->text);
        $this->assertSame("foo\$'\\n'bar", $this->getCmd($this->parse("echo foo\$'\\n'bar"))->suffix[0]->text);
    }

    // ── Word.value (dequoted) ─────────────────────────────────────────

    public function testValueStripsDoubleQuotes(): void
    {
        $this->assertSame('hello world', $this->getCmd($this->parse('echo "hello world"'))->suffix[0]->value);
    }

    public function testValueStripsSingleQuotes(): void
    {
        $this->assertSame('hello world', $this->getCmd($this->parse("echo 'hello world'"))->suffix[0]->value);
    }

    public function testValueOnUnquotedWordEqualsText(): void
    {
        $this->assertSame('hello', $this->getCmd($this->parse('echo hello'))->suffix[0]->value);
    }

    public function testValueStripsUnquotedBackslashEscapes(): void
    {
        $this->assertSame('hello world', $this->getCmd($this->parse('echo hello\ world'))->suffix[0]->value);
        $this->assertSame(
            '/Applications/Visual Studio Code.app',
            $this->getCmd($this->parse('/Applications/Visual\ Studio\ Code.app --wait'))->name->value
        );
    }

    public function testValueJoinsAdjacentQuotedSegments(): void
    {
        $this->assertSame('foobarbaz', $this->getCmd($this->parse("echo 'foo'\"bar\"baz"))->suffix[0]->value);
    }

    public function testValueInterpretsAnsiCEscapes(): void
    {
        $this->assertSame("hello\nworld", $this->getCmd($this->parse("echo \$'hello\\nworld'"))->suffix[0]->value);
    }

    public function testValuePreservesExpansionText(): void
    {
        $this->assertSame('$HOME/bin', $this->getCmd($this->parse('echo "$HOME/bin"'))->suffix[0]->value);
    }

    public function testValueOnCommandNameWithQuotes(): void
    {
        $this->assertSame('if', $this->getCmd($this->parse('"if" true'))->name->value);
    }

    // ── Line continuation ─────────────────────────────────────────────

    public function testBackslashNewlineMidWordJoins(): void
    {
        $this->assertSame("ech\\\no", $this->getCmd($this->parse("ech\\\no hello"))->name->text);
    }

    public function testBackslashNewlineBetweenTokens(): void
    {
        $c = $this->getCmd($this->parse("echo \\\nhello"));
        $this->assertSame('echo', $c->name->text);
        $this->assertSame('hello', $c->suffix[0]->text);
    }

    public function testMultipleLineContinuationsInOneWord(): void
    {
        $this->assertSame("fo\\\no\\\nba\\\nr", $this->getCmd($this->parse("fo\\\no\\\nba\\\nr"))->name->text);
    }

    public function testLineContinuationMidKeyword(): void
    {
        $ast = $this->parse("wh\\\nile true; do echo yes; done");
        $this->assertSame('While', $ast->commands[0]->command->type);
    }

    public function testLeadingLineContinuationsSkipped(): void
    {
        $ast = $this->parse("\\\n\\\n\\\necho world");
        $this->assertSame('echo', $this->getCmd($ast)->name->text);
    }

    public function testLineContinuationInWhitespaceBetweenTokens(): void
    {
        $this->assertCount(2, $this->parse("echo; \\\nls")->commands);
    }

    // ── Single-quoted strings are fully literal ───────────────────────

    public function testBackticksInsideSingleQuotesLiteral(): void
    {
        $word = $this->getCmd($this->parse("echo '`cmd`'"))->suffix[0];
        $this->assertCount(1, $word->parts);
        $this->assertSame('SingleQuoted', $word->parts[0]->type);
        $this->assertSame('`cmd`', $word->parts[0]->value);
    }

    public function testDollarParenInsideSingleQuotesLiteral(): void
    {
        $word = $this->getCmd($this->parse("echo '\$(cmd)'"))->suffix[0];
        $this->assertCount(1, $word->parts);
        $this->assertSame('SingleQuoted', $word->parts[0]->type);
        $this->assertSame('$(cmd)', $word->parts[0]->value);
    }

    public function testBraceInsideSingleQuotesLiteral(): void
    {
        $word = $this->getCmd($this->parse("echo '\${var}'"))->suffix[0];
        $this->assertCount(1, $word->parts);
        $this->assertSame('SingleQuoted', $word->parts[0]->type);
        $this->assertSame('${var}', $word->parts[0]->value);
    }

    public function testMultilineSingleQuotedWithBackticks(): void
    {
        $word = $this->getCmd($this->parse("echo '\nconst x = `hello`;\nconsole.log(x);\n'"))->suffix[0];
        $this->assertCount(1, $word->parts);
        $this->assertSame('SingleQuoted', $word->parts[0]->type);
        $this->assertStringContainsString('`hello`', $word->parts[0]->value);
    }

    public function testMultilineSingleQuotedWithDollarParen(): void
    {
        $word = $this->getCmd($this->parse("echo '\nconst src = \"for (( i = \$(start); i < \$(limit); i++ )); do echo \$i; done\";\n'"))->suffix[0];
        $this->assertCount(1, $word->parts);
        $this->assertSame('SingleQuoted', $word->parts[0]->type);
        $this->assertStringContainsString('$(start)', $word->parts[0]->value);
        $this->assertStringContainsString('$(limit)', $word->parts[0]->value);
    }
}
