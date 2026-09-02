<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

/** Ported from test/brace-expansion.test.ts and test/process-substitution.test.ts */
final class BraceProcSubPortTest extends RefTestCase
{
    /** @return Node[]|null */
    private function wp(Word $w): ?array
    {
        return $w->parts;
    }

    // ── Brace expansion ──────────────────────────────────────────────

    public function testBraceExpansionList(): void
    {
        $c = $this->getCmd($this->parse('echo {a,b,c}'));
        $part = $this->wp($c->suffix[0])[0];
        $this->assertSame('BraceExpansion', $part->type);
        $this->assertSame('{a,b,c}', $part->text);
    }

    public function testBraceExpansionRange(): void
    {
        $c = $this->getCmd($this->parse('echo {1..10}'));
        $this->assertSame('BraceExpansion', $this->wp($c->suffix[0])[0]->type);
    }

    public function testBraceExpansionStep(): void
    {
        $c = $this->getCmd($this->parse('echo {01..100..5}'));
        $this->assertSame('BraceExpansion', $this->wp($c->suffix[0])[0]->type);
    }

    public function testBraceExpansionWithSuffix(): void
    {
        $c = $this->getCmd($this->parse('echo {a,b,c}.txt'));
        $parts = $this->wp($c->suffix[0]);
        $this->assertSame('BraceExpansion', $parts[0]->type);
        $this->assertSame('{a,b,c}', $parts[0]->text);
        $this->assertSame('Literal', $parts[1]->type);
        $this->assertSame('.txt', $parts[1]->value);
    }

    public function testBraceExpansionWithPrefix(): void
    {
        $c = $this->getCmd($this->parse('echo file{1,2,3}'));
        $parts = $this->wp($c->suffix[0]);
        $this->assertSame('Literal', $parts[0]->type);
        $this->assertSame('file', $parts[0]->value);
        $this->assertSame('BraceExpansion', $parts[1]->type);
    }

    public function testBraceExpansionTextPreserved(): void
    {
        $this->assertSame('{a,b,c}.txt', $this->getCmd($this->parse('echo {a,b,c}.txt'))->suffix[0]->text);
    }

    public function testNestedBraceExpansion(): void
    {
        $c = $this->getCmd($this->parse('echo {a,{b,c}}'));
        $this->assertSame('BraceExpansion', $this->wp($c->suffix[0])[0]->type);
    }

    public function testSingleItemBraceIsNotExpansion(): void
    {
        $c = $this->getCmd($this->parse('echo {foo}'));
        $this->assertNull($this->wp($c->suffix[0]));
        $this->assertSame('{foo}', $c->suffix[0]->text);
    }

    public function testEmptyBraceIsNotExpansion(): void
    {
        $this->assertSame('{}', $this->getCmd($this->parse('echo {}'))->suffix[0]->text);
    }

    public function testMultipleBraceExpansions(): void
    {
        $c = $this->getCmd($this->parse('echo {a,b}{c,d}'));
        $parts = $this->wp($c->suffix[0]);
        $count = count(array_filter($parts, static fn (Node $p) => $p->type === 'BraceExpansion'));
        $this->assertSame(2, $count);
    }

    public function testCommandSubstitutionsInsideBrace(): void
    {
        $c = $this->getCmd($this->parse('echo {safe,$(danger)}'));
        $part = $this->wp($c->suffix[0])[0];
        $this->assertSame('BraceExpansion', $part->type);
        $expansion = null;
        foreach ($part->parts as $child) {
            if ($child->type === 'CommandExpansion') {
                $expansion = $child;
            }
        }
        $this->assertNotNull($expansion);
        $this->assertSame('danger', $expansion->script->commands[0]->command->name->value);
    }

    public function testProcessSubstitutionsInsideBrace(): void
    {
        $c = $this->getCmd($this->parse('echo {safe,<(danger)}'));
        $part = $this->wp($c->suffix[0])[0];
        $this->assertSame('BraceExpansion', $part->type);
        $expansion = null;
        foreach ($part->parts as $child) {
            if ($child->type === 'ProcessSubstitution') {
                $expansion = $child;
            }
        }
        $this->assertNotNull($expansion);
        $this->assertSame('danger', $expansion->script->commands[0]->command->name->value);
    }

    // ── Process substitution ─────────────────────────────────────────

    public function testProcessSubstitutionInput(): void
    {
        $c = $this->getCmd($this->parse('diff <(sort a) <(sort b)'));
        $this->assertCount(2, $c->suffix);
        $this->assertNotNull($this->wp($c->suffix[0]));
        $this->assertSame('ProcessSubstitution', $this->wp($c->suffix[0])[0]->type);
        $this->assertSame('<', $this->wp($c->suffix[0])[0]->operator);
        $this->assertNotNull($this->wp($c->suffix[0])[0]->script);
    }

    public function testProcessSubstitutionOutput(): void
    {
        $c = $this->getCmd($this->parse('tee >(grep err)'));
        $this->assertSame('ProcessSubstitution', $this->wp($c->suffix[0])[0]->type);
        $this->assertSame('>', $this->wp($c->suffix[0])[0]->operator);
    }

    public function testProcessSubstitutionTextPreserved(): void
    {
        $this->assertSame('<(echo hello)', $this->getCmd($this->parse('cat <(echo hello)'))->suffix[0]->text);
    }

    public function testProcessSubstitutionScriptParsed(): void
    {
        $c = $this->getCmd($this->parse('cat <(echo hello)'));
        $ps = $this->wp($c->suffix[0])[0];
        $this->assertSame('echo', $ps->script->commands[0]->command->name->text);
    }

    public function testMultipleProcessSubstitutions(): void
    {
        $c = $this->getCmd($this->parse('paste <(cut -f1 a) <(cut -f2 a)'));
        $this->assertCount(2, $c->suffix);
        $this->assertSame('ProcessSubstitution', $this->wp($c->suffix[0])[0]->type);
        $this->assertSame('ProcessSubstitution', $this->wp($c->suffix[1])[0]->type);
    }

    public function testRedirectToProcessSubstitution(): void
    {
        $c = $this->getCmd($this->parse('cmd > >(tee log)'));
        $this->assertNotEmpty($c->redirects);
        $this->assertSame('>', $c->redirects[0]->operator);
    }

    public function testOutputProcSubInnerScripts(): void
    {
        $c = $this->getCmd($this->parse('tee >(grep pattern > matches.txt) >(wc -l > count.txt)'));
        $ps1 = $this->wp($c->suffix[0])[0];
        $ps2 = $this->wp($c->suffix[1])[0];
        $this->assertSame('ProcessSubstitution', $ps1->type);
        $this->assertSame('ProcessSubstitution', $ps2->type);
        $this->assertSame('grep', $ps1->script->commands[0]->command->name->text);
        $this->assertSame(['pattern'], $this->args($ps1->script->commands[0]->command));
        $this->assertSame('wc', $ps2->script->commands[0]->command->name->text);
    }

    public function testInputProcSubInnerScripts(): void
    {
        $c = $this->getCmd($this->parse('diff <(sort file1) <(sort file2)'));
        $ps1 = $this->wp($c->suffix[0])[0];
        $ps2 = $this->wp($c->suffix[1])[0];
        $this->assertSame('sort', $ps1->script->commands[0]->command->name->text);
        $this->assertSame('sort', $ps2->script->commands[0]->command->name->text);
    }

    public function testInputProcSubPreservesStructure(): void
    {
        $c = $this->getCmd($this->parse('wc -c <(echo abc && echo def)'));
        $ps = $this->wp($c->suffix[1])[0];
        $this->assertSame('ProcessSubstitution', $ps->type);
        $inner = $ps->script->commands[0]->command;
        $this->assertSame('AndOr', $inner->type);
        $this->assertSame(['&&'], $inner->operators);
    }

    public function testProcSubInWhileLoopRedirect(): void
    {
        $src = 'while read line; do echo $line; done < <(cat file)';
        $ast = $this->parse($src);
        $stmt = $ast->commands[0];
        $this->assertSame('While', $stmt->command->type);
        $ps = $stmt->redirects[0]->target?->parts[0];
        $this->assertSame('ProcessSubstitution', $ps->type);
        $this->assertSame('cat', $ps->script->commands[0]->command->name->text);
    }

    public function testTeeWithMultipleProcSubAndRedirect(): void
    {
        $ast = $this->parse('echo data | tee >(grep pattern > matches.txt) >(wc -l > count.txt) > /dev/null');
        $this->assertGreaterThan(0, count($ast->commands));
    }

    public function testMapfileWithProcSub(): void
    {
        $ast = $this->parse("mapfile -t ITEMS < <(echo \"\$URL\" | tr '/' '\\n')");
        $this->assertGreaterThan(0, count($ast->commands));
    }

    public function testRedirectPlusProcSubWithSpace(): void
    {
        $ast = $this->parse('cmd < <(echo data)');
        $this->assertGreaterThan(0, count($ast->commands));
        $c = $this->getCmd($ast);
        $found = null;
        foreach ($c->redirects[0]->target->parts as $p) {
            if ($p->type === 'ProcessSubstitution') {
                $found = $p;
            }
        }
        $this->assertNotNull($found);
    }
}
