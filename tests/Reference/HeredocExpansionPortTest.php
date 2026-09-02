<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;

/** Ported from webpro-nl/unbash test/heredoc-expansion.test.ts */
final class HeredocExpansionPortTest extends RefTestCase
{
    private function getRedirect(string $src, int $i = 0, int $ri = 0): Node
    {
        return $this->getCmd($this->parse($src), $i)->redirects[$ri];
    }

    public function testUnquotedBodyWithParts(): void
    {
        $r = $this->getRedirect("cat <<EOF\nHello \$name\nEOF\n");
        $this->assertSame('<<', $r->operator);
        $this->assertSame("Hello \$name\n", $r->content);
        $this->assertNotNull($r->body);
        $this->assertNotNull($r->body->parts);
        $this->assertSame("Hello \$name\n", $r->body->text);
    }

    public function testUnquotedBodySimpleExpansionParts(): void
    {
        $parts = $this->getRedirect("cat <<EOF\nHello \$name world\nEOF\n")->body->parts;
        $this->assertSame('Literal', $parts[0]->type);
        $this->assertSame('Hello ', $parts[0]->value);
        $this->assertSame('SimpleExpansion', $parts[1]->type);
        $this->assertSame('$name', $parts[1]->text);
        $this->assertSame('Literal', $parts[2]->type);
        $this->assertSame(" world\n", $parts[2]->value);
    }

    public function testUnquotedParamExpansion(): void
    {
        $parts = $this->getRedirect("cat <<EOF\n\${var:-default}\nEOF\n")->body->parts;
        $pe = $this->findType($parts, 'ParameterExpansion');
        $this->assertNotNull($pe);
        $this->assertSame('var', $pe->parameter);
    }

    public function testUnquotedCommandSubstitution(): void
    {
        $parts = $this->getRedirect("cat <<EOF\ndir: \$(pwd)\nEOF\n")->body->parts;
        $cs = $this->findType($parts, 'CommandExpansion');
        $this->assertNotNull($cs);
        $this->assertNotNull($cs->script);
    }

    public function testUnquotedArithmetic(): void
    {
        $parts = $this->getRedirect("cat <<EOF\nresult: \$((1+2))\nEOF\n")->body->parts;
        $ae = $this->findType($parts, 'ArithmeticExpansion');
        $this->assertNotNull($ae);
        $this->assertNotNull($ae->expression);
    }

    public function testUnquotedBacktick(): void
    {
        $parts = $this->getRedirect("cat <<EOF\nhost: `hostname`\nEOF\n")->body->parts;
        $this->assertNotNull($this->findType($parts, 'CommandExpansion'));
    }

    public function testUnquotedMultipleExpansions(): void
    {
        $parts = $this->getRedirect("cat <<EOF\n\$name has \$count items\nEOF\n")->body->parts;
        $count = count(array_filter($parts, fn (Node $p) => $p->type === 'SimpleExpansion'));
        $this->assertSame(2, $count);
    }

    public function testUnquotedEscapedDollar(): void
    {
        $parts = $this->getRedirect("cat <<EOF\n\\\$literal and \$real\nEOF\n")->body->parts;
        $se = array_values(array_filter($parts, fn (Node $p) => $p->type === 'SimpleExpansion'));
        $this->assertCount(1, $se);
        $this->assertSame('$real', $se[0]->text);
    }

    public function testUnquotedPreservesContent(): void
    {
        $r = $this->getRedirect("cat <<EOF\nHello \$name\nEOF\n");
        $this->assertSame("Hello \$name\n", $r->content);
        $this->assertNotNull($r->body);
    }

    public function testUnquotedNoHeredocQuoted(): void
    {
        $this->assertNull($this->getRedirect("cat <<EOF\ntext\nEOF\n")->heredocQuoted);
    }

    public function testSingleQuotedSuppresses(): void
    {
        $r = $this->getRedirect("cat <<'EOF'\n\$name \${var}\nEOF\n");
        $this->assertTrue($r->heredocQuoted);
        $this->assertNull($r->body);
        $this->assertSame("\$name \${var}\n", $r->content);
    }

    public function testDoubleQuotedSuppresses(): void
    {
        $r = $this->getRedirect("cat <<\"EOF\"\n\$name\nEOF\n");
        $this->assertTrue($r->heredocQuoted);
        $this->assertNull($r->body);
    }

    public function testBackslashDelimiterSuppresses(): void
    {
        $r = $this->getRedirect("cat <<\\EOF\n\$name\nEOF\n");
        $this->assertTrue($r->heredocQuoted);
        $this->assertNull($r->body);
    }

    public function testStripUnquotedHasBody(): void
    {
        $r = $this->getRedirect("cat <<-EOF\n\tHello \$name\nEOF\n");
        $this->assertSame('<<-', $r->operator);
        $this->assertNotNull($this->findType($r->body->parts, 'SimpleExpansion'));
    }

    public function testStripQuotedNoBody(): void
    {
        $r = $this->getRedirect("cat <<-'EOF'\n\t\$name\nEOF\n");
        $this->assertTrue($r->heredocQuoted);
        $this->assertNull($r->body);
    }

    public function testUnquotedNoExpansionsNoBody(): void
    {
        $r = $this->getRedirect("cat <<EOF\njust plain text\nEOF\n");
        $this->assertNull($r->body);
        $this->assertSame("just plain text\n", $r->content);
    }

    public function testEmptyBody(): void
    {
        $r = $this->getRedirect("cat <<EOF\nEOF\n");
        $this->assertSame('', $r->content);
        $this->assertNull($r->body);
    }

    public function testWhitespaceOnly(): void
    {
        $this->assertNull($this->getRedirect("cat <<EOF\n   \nEOF\n")->body);
    }

    public function testBareDollarAtEol(): void
    {
        $this->assertNull($this->getRedirect("cat <<EOF\nprice: \$\nEOF\n")->body);
    }

    /** @param Node[] $parts */
    private function findType(array $parts, string $type): ?Node
    {
        foreach ($parts as $p) {
            if ($p->type === $type) {
                return $p;
            }
        }

        return null;
    }
}
