<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;

/** Ported from webpro-nl/unbash test/assignments.test.ts */
final class AssignmentsPortTest extends RefTestCase
{
    private function getAssign(string $src, int $i = 0): Node
    {
        $cmd = $this->getCmd($this->parse($src));
        $assigns = array_values(array_filter($cmd->prefix, fn (Node $p) => $p->type === 'Assignment'));

        return $assigns[$i];
    }

    private function assertCommandGroups(string $source, array $expected): Node
    {
        $ast = $this->parse($source);
        $this->assertNull($ast->errors, $source);
        $this->assertSame($expected, array_map(function ($s) {
            $command = $s->command;
            if ($command->type === 'Command') {
                return ['Command', $s->pos, $s->end, $command->name?->text, array_map(fn (Node $a) => $a->name, $command->prefix)];
            }

            return [$command->type, $s->pos, $s->end];
        }, $ast->commands), $source);

        return $ast;
    }

    public function testScalar(): void
    {
        $a = $this->getAssign('x=hello');
        $this->assertSame('x', $a->name);
        $this->assertSame('hello', $a->value->text);
        $this->assertNull($a->append);
        $this->assertNull($a->index);
        $this->assertNull($a->array);
        $this->assertSame('', $this->getAssign('IFS=')->value->text);
        $this->assertSame('b=c', $this->getAssign('a=b=c')->value->text);
        $this->assertSame('/usr/local/bin', $this->getAssign('PATH=/usr/local/bin')->value->text);
    }

    public function testAppend(): void
    {
        $a = $this->getAssign('x+=more');
        $this->assertSame('x', $a->name);
        $this->assertTrue($a->append);
        $this->assertSame('more', $a->value->text);
        $e = $this->getAssign('x+=');
        $this->assertTrue($e->append);
        $this->assertSame('', $e->value->text);
    }

    public function testIndexed(): void
    {
        $a = $this->getAssign('x[0]=val');
        $this->assertSame('x', $a->name);
        $this->assertSame('0', $a->index);
        $this->assertSame('val', $a->value->text);
        $this->assertSame('idx', $this->getAssign('x[idx]=val')->index);
        $ap = $this->getAssign('x[0]+=val');
        $this->assertSame('0', $ap->index);
        $this->assertTrue($ap->append);
    }

    public function testArray(): void
    {
        $a = $this->getAssign('x=(a b c)');
        $this->assertSame('x', $a->name);
        $this->assertNotNull($a->array);
        $this->assertSame(['a', 'b', 'c'], array_map(fn ($w) => $w->text, $a->array));
        $ap = $this->getAssign('x+=(d e)');
        $this->assertTrue($ap->append);
        $this->assertCount(2, $ap->array);
        $this->assertCount(0, $this->getAssign('x=()')->array);
        $q = $this->getAssign('x=("hello world" \'literal\')');
        $this->assertSame(['"hello world"', "'literal'"], array_map(fn ($w) => $w->text, $q->array));
    }

    public function testArrayCommentsSkipped(): void
    {
        foreach (["# don't", '# say "hi', '# `cmd'] as $comment) {
            $src = "x=(\n a\n $comment\n b\n)\necho after";
            $ast = $this->parse($src);
            $this->assertNull($ast->errors, $src);
            $this->assertCount(2, $ast->commands, $src);
            $this->assertSame(['a', 'b'], array_map(fn ($w) => $w->text, $this->getAssign($src)->array), $src);
        }
    }

    public function testArrayCommentBoundary(): void
    {
        $this->assertSame(['y'], array_map(fn ($w) => $w->text, $this->getAssign("x=(#c\ny)")->array));
        $this->assertSame(['a#b'], array_map(fn ($w) => $w->text, $this->getAssign('x=(a#b)')->array));
        $this->assertSame(['"q"#b'], array_map(fn ($w) => $w->text, $this->getAssign('x=("q"#b)')->array));
    }

    public function testArrayCommandSubstitution(): void
    {
        $a = $this->getAssign('x=($(seq 1 5))');
        $this->assertCount(1, $a->array);
        $this->assertSame('CommandExpansion', $a->array[0]->parts[0]->type);
    }

    public function testAssociativeArray(): void
    {
        $a = $this->getAssign('x=([a]=1 [b]=2)');
        $this->assertCount(2, $a->array);
    }

    public function testValueWithExpansions(): void
    {
        $a = $this->getAssign('x=$HOME/bin');
        $this->assertSame('$HOME/bin', $a->value->text);
        $this->assertSame('SimpleExpansion', $a->value->parts[0]->type);
        $y = $this->getAssign('y=$(echo hi)');
        $this->assertSame('CommandExpansion', $y->value->parts[0]->type);
        $z = $this->getAssign('z="hello $name"');
        $this->assertSame('"hello $name"', $z->value->text);
        $this->assertSame('DoubleQuoted', $z->value->parts[0]->type);
        $p = $this->getAssign('x=${var:-default}');
        $this->assertSame('ParameterExpansion', $p->value->parts[0]->type);
    }

    public function testRepeatedExpansionsInValue(): void
    {
        $source = 'var=$ITEM/word-$ITEM/a/b';
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $value = $this->getCmd($ast)->prefix[0]->value;
        $this->assertSame(['$ITEM/word-$ITEM/a/b', 4, 24], [$value->text, $value->pos, $value->end]);
        $this->assertSame([
            ['SimpleExpansion', '$ITEM'],
            ['Literal', '/word-'],
            ['SimpleExpansion', '$ITEM'],
            ['Literal', '/a/b'],
        ], array_map(fn ($p) => [$p->type, $p->text], $value->parts));
    }

    public function testMultipleAssignments(): void
    {
        $this->assertSame('A', $this->getAssign('A=1 B=2 cmd', 0)->name);
        $this->assertSame('B', $this->getAssign('A=1 B=2 cmd', 1)->name);
        $cmd = $this->getCmd($this->parse('NODE_ENV=production node app.js'));
        $this->assertSame('NODE_ENV', $cmd->prefix[0]->name);
        $this->assertSame('node', $cmd->name->text);
    }

    public function testAssignmentOnlyEndsAtNewlineBeforeIf(): void
    {
        $this->assertCommandGroups("a= c=\nb=\nif true; then true; fi", [
            ['Command', 0, 5, null, ['a', 'c']],
            ['Command', 6, 8, null, ['b']],
            ['If', 9, 31],
        ]);
        $this->assertCommandGroups("a= c= b=\nif true; then true; fi", [
            ['Command', 0, 8, null, ['a', 'c', 'b']],
            ['If', 9, 31],
        ]);
    }

    public function testMultipleAssignmentsNoAbsorbAcrossNewline(): void
    {
        $this->assertCommandGroups("a=a\nb=b c=c\nexport a\nexport b c", [
            ['Command', 0, 3, null, ['a']],
            ['Command', 4, 11, null, ['b', 'c']],
            ['Command', 12, 20, 'export', []],
            ['Command', 21, 31, 'export', []],
        ]);
    }

    public function testTextFields(): void
    {
        $this->assertSame('x=hello', $this->getAssign('x=hello')->text);
        $this->assertSame('x=(a b c)', $this->getAssign('x=(a b c)')->text);
        $this->assertSame('x+=more', $this->getAssign('x+=more')->text);
        $this->assertSame('x[0]=val', $this->getAssign('x[0]=val')->text);
    }

    public function testAssignmentPrefixAndOnly(): void
    {
        $c = $this->getCmd($this->parse('NODE_ENV=production program'));
        $this->assertSame('program', $c->name->text);
        $this->assertSame('NODE_ENV=production', $c->prefix[0]->text);
        $only = $this->getCmd($this->parse('FOO=bar'));
        $this->assertNull($only->name);
        $this->assertSame('FOO=bar', $only->prefix[0]->text);
    }

    public function testDeclareArrays(): void
    {
        $c = $this->getCmd($this->parse('declare -a arr=(one two three)'));
        $this->assertSame('declare', $c->name->text);
        $this->assertSame(['-a', 'arr=(one two three)'], array_map(fn ($s) => $s->text, $c->suffix));
        $c2 = $this->getCmd($this->parse('declare -A map=([a]=1 [b]=2)'));
        $this->assertSame(['-A', 'map=([a]=1 [b]=2)'], array_map(fn ($s) => $s->text, $c2->suffix));
    }

    public function testAssignmentEdgeCases(): void
    {
        $c = $this->getCmd($this->parse('echo FOO=bar'));
        $this->assertSame('echo', $c->name->text);
        $this->assertSame('FOO=bar', $c->suffix[0]->text);
        $c2 = $this->getCmd($this->parse('IFS= read -r line'));
        $found = false;
        foreach ($c2->prefix as $p) {
            if ($p->type === 'Assignment' && $p->text === 'IFS=') {
                $found = true;
            }
        }
        $this->assertTrue($found);
        $this->assertSame('read', $c2->name->text);
        $this->assertSame('=a', $this->getCmd($this->parse('echo =a'))->suffix[0]->text);
    }

    public function testQuotedAssignmentNameIsCommandWord(): void
    {
        foreach ([
            ['X\=1 true', 'X\=1', 'X=1'],
            ['X"="1 true', 'X"="1', 'X=1'],
            ['"X"=1 true', '"X"=1', 'X=1'],
            ['"array"[key]=value true', '"array"[key]=value', 'array[key]=value'],
            ['array\[key]=value true', 'array\[key]=value', 'array[key]=value'],
        ] as [$source, $raw, $value]) {
            $c = $this->getCmd($this->parse($source));
            $this->assertCount(0, $c->prefix, $source);
            $this->assertSame($raw, $c->name->text, $source);
            $this->assertSame($value, $c->name->value, $source);
            $this->assertSame(['true'], array_map(fn ($w) => $w->text, $c->suffix), $source);
        }
    }

    public function testNestedAndExpandedIndexes(): void
    {
        foreach ([
            ['array[nested[0]]=value true', 'nested[0]'],
            ['array[i=0]=value true', 'i=0'],
            ['array[$((nested[0]))]=value true', '$((nested[0]))'],
            ['array[`echo ]`]=value true', '`echo ]`'],
            ['array[$(echo ] >/dev/null; printf 0)]=value true', '$(echo ] >/dev/null; printf 0)'],
        ] as [$source, $index]) {
            $c = $this->getCmd($this->parse($source));
            $this->assertCount(1, $c->prefix, $source);
            $this->assertSame('Assignment', $c->prefix[0]->type, $source);
            $this->assertSame('array', $c->prefix[0]->name, $source);
            $this->assertSame($index, $c->prefix[0]->index, $source);
            $this->assertSame('value', $c->prefix[0]->value->text, $source);
            $this->assertSame('true', $c->name->text, $source);
        }
    }

    public function testSubscriptMatchedPair(): void
    {
        foreach ([
            ['a[1 + 2]=7', '1 + 2', '7'],
            ['a[3|4]=8', '3|4', '8'],
            ['a[(1+2)*3]=9', '(1+2)*3', '9'],
            ['a[x[(1)]]=9', 'x[(1)]', '9'],
            ['a[1 && 2]=9', '1 && 2', '9'],
            ['a[1 > 2]=9', '1 > 2', '9'],
            ['a["x y"]=9', '"x y"', '9'],
        ] as [$source, $index, $value]) {
            $c = $this->getCmd($this->parse($source));
            $this->assertCount(1, $c->prefix, $source);
            $this->assertSame('a', $c->prefix[0]->name, $source);
            $this->assertSame($index, $c->prefix[0]->index, $source);
            $this->assertSame($value, $c->prefix[0]->value->text, $source);
            $this->assertNull($this->parse($source)->errors, $source);
        }
    }

    public function testSubscriptOutsideAssignmentPosition(): void
    {
        $this->assertSame('Pipeline', $this->parse('echo a[3|4]=8')->commands[0]->command->type);
        $this->assertCount(3, $this->parse('echo a[1 + 2]=7')->commands[0]->command->suffix);
        $ast = $this->parse("a[1 + 2\necho hi");
        $this->assertCount(2, $ast->commands);
        $this->assertSame('echo', $ast->commands[1]->command->name->text);
    }

    public function testInterleavedPrefix(): void
    {
        $c = $this->getCmd($this->parse('A=1 >/dev/null B=2 2>&1 C=3 cmd arg'));
        $this->assertSame('cmd', $c->name->text);
        $this->assertSame(['A=1', 'B=2', 'C=3'], array_map(fn (Node $p) => "{$p->name}={$p->value->text}", $c->prefix));
        $this->assertSame(['>', '>&'], array_map(fn ($r) => $r->operator, $c->redirects));
        $this->assertSame(['arg'], array_map(fn ($w) => $w->text, $c->suffix));
    }

    public function testPrefixDemotesReservedWord(): void
    {
        foreach ([
            ['FOO=bar for', 'for', 1, 0],
            ['FOO=bar if', 'if', 1, 0],
            ['FOO=bar [[ foo == foo ]]', '[[', 1, 0],
            ['FOO=bar {', '{', 1, 0],
            ['FOO=bar time', 'time', 1, 0],
            ['>/dev/null for', 'for', 0, 1],
            ['FOO=bar 2>/dev/null while', 'while', 1, 1],
        ] as [$source, $name, $prefixLength, $redirectCount]) {
            $c = $this->getCmd($this->parse($source));
            $this->assertSame('Command', $c->type, $source);
            $this->assertSame($name, $c->name->text, $source);
            $this->assertCount($prefixLength, $c->prefix, $source);
            $this->assertCount($redirectCount, $c->redirects, $source);
            $this->assertNull($this->parse($source)->errors, $source);
        }
    }
}
