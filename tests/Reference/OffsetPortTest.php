<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

/** Ported from webpro-nl/unbash test/offset.test.ts */
final class OffsetPortTest extends RefTestCase
{
    private function check(string $source, mixed $value): void
    {
        if ($value instanceof Word) {
            $this->assertSame(substr($source, $value->pos, $value->end - $value->pos), $value->text, "word span at {$value->pos}");
            if ($value->parts !== null) {
                foreach ($value->parts as $p) {
                    $this->check($source, $p);
                }
            }

            return;
        }
        if ($value instanceof Node) {
            foreach ($value->properties() as $v) {
                $this->check($source, $v);
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                $this->check($source, $v);
            }
        }
    }

    /** @dataProvider corpus */
    public function testEveryWordSpanMapsToSource(string $command): void
    {
        $this->check($command, $this->parse($command));
        $this->addToAssertionCount(1);
    }

    /** @return array<int, array{0:string}> */
    public static function corpus(): array
    {
        $c = [
            'echo hello',
            'echo "hi $(rm -rf /tmp/z)"',
            'a $(b $(c "deep"))',
            'diff <(sort x) <(sort y)',
            'diff <(sort <(gen a)) <(sort y)',
            'echo "$(date) and $(whoami)"',
            'for f in $(ls /etc); do cat "$f"; done',
            'x=$(foo bar); echo "$x"',
            'cat "prefix $(inner /a/b) suffix"',
            'result=$(echo "$(nested cmd)")',
            'grep -r "$(cat patterns.txt)" .',
            'echo $(a) $(b) $(c)',
            'if test -f "$(which node)"; then echo ok; fi',
            'cat <(echo $(date))tail',
            'echo $((1 + 2))',
            'x=$(echo $((1 + 2)))',
            'echo $(( $(id -u) + 1 ))',
            'echo ${x:-$(date)}',
            'echo ${x/foo/$(repl)}',
            'echo ${x:$(off):$(len)}',
            'echo ${a:-${b:-$(deep)}}',
            'echo {safe,$(brace)}',
            'echo @($(extglob)|<(process))',
            'echo ${array[$(parameter)]}',
            'array[$(assignment)]=value true',
            'echo $((array[$(arithmetic)]))',
        ];

        return array_map(static fn ($s) => [$s], $c);
    }

    public function testNestedCommandSourceSlices(): void
    {
        $command = 'echo $(rm -rf /tmp/z)';
        $word = $this->getCmd($this->parse($command))->suffix[0];
        $sub = null;
        foreach ($word->parts as $p) {
            if ($p->type === 'CommandExpansion') {
                $sub = $p;
            }
        }
        $rm = $sub->script->commands[0]->command;
        $this->assertSame('rm -rf /tmp/z', substr($command, $rm->pos, $rm->end - $rm->pos));
        $this->assertSame('rm', substr($command, $rm->name->pos, $rm->name->end - $rm->name->pos));
    }

    public function testArithmeticExpressionOffsetsAbsolute(): void
    {
        foreach (['echo $((1 + 2))', 'x=$(echo $((1 + 2)))'] as $command) {
            $at = strpos($command, '1 + 2');
            if (str_starts_with($command, 'echo')) {
                $arith = $this->suffixPart($command, 'ArithmeticExpansion');
            } else {
                $ce = null;
                foreach ($this->getCmd($this->parse($command))->prefix[0]->value->parts as $p) {
                    if ($p->type === 'CommandExpansion') {
                        $ce = $p;
                    }
                }
                $arith = null;
                foreach ($ce->script->commands[0]->command->suffix[0]->parts as $p) {
                    if ($p->type === 'ArithmeticExpansion') {
                        $arith = $p;
                    }
                }
            }
            $bin = $arith->expression;
            $this->assertSame($at, $bin->left->pos, $command);
            $this->assertSame('2', substr($command, $bin->right->pos, $bin->right->end - $bin->right->pos), $command);
        }
    }

    public function testArithmeticCommandSubstitutionAbsolute(): void
    {
        $command = 'echo $(( $(id -u) + 1 ))';
        $arith = $this->suffixPart($command, 'ArithmeticExpansion');
        $ace = $this->findWalk($arith->expression, 'ArithmeticCommandExpansion');
        $cmd = $ace->script->commands[0]->command;
        $this->assertSame('id -u', substr($command, $cmd->pos, $cmd->end - $cmd->pos));
        $this->assertSame('id', substr($command, $cmd->name->pos, $cmd->name->end - $cmd->name->pos));
    }

    public function testArithmeticDoesNotConsumeCmdSubClose(): void
    {
        $command = 'echo $((1 + $(danger)))';
        $word = $this->getCmd($this->parse($command))->suffix[0];
        $this->assertSame('$((1 + $(danger)))', $word->text);
        $arith = null;
        foreach ($word->parts as $p) {
            if ($p->type === 'ArithmeticExpansion') {
                $arith = $p;
            }
        }
        $substitution = $arith->expression->right;
        $this->assertSame('$(danger)', $substitution->text);
        $nested = $substitution->script->commands[0]->command;
        $this->assertSame('danger', substr($command, $nested->pos, $nested->end - $nested->pos));
    }

    public function testArithmeticCommandAndForSubstitutionsAbsolute(): void
    {
        $cases = [
            ['((x=$(danger)))', ['danger']],
            ['for (( i = $(init); i < $(limit); i++ )); do echo "$i"; done', ['init', 'limit']],
        ];
        foreach ($cases as [$command, $expected]) {
            $root = $this->parse($command)->commands[0]->command;
            $expressions = $root->type === 'ArithmeticCommand'
                ? [$root->expression]
                : [$root->initialize, $root->test, $root->update];
            $actual = [];
            foreach ($expressions as $expr) {
                $this->collectAce($expr, $command, $actual);
            }
            $this->assertSame($expected, $actual, $command);
        }
    }

    public function testCommandSubstitutionInsideParamOperandAbsolute(): void
    {
        foreach ([
            ['echo ${x:-$(date)}', 'date'],
            ['echo ${x/foo/$(repl args)}', 'repl args'],
            ['echo ${a:-${b:-$(deep cmd)}}', 'deep cmd'],
        ] as [$command, $expected]) {
            $cmd = $this->findNonEchoCommand($this->parse($command), $command);
            $this->assertNotNull($cmd, $command);
            $this->assertSame($expected, substr($command, $cmd->pos, $cmd->end - $cmd->pos), $command);
        }
    }

    private function suffixPart(string $command, string $type): ?Node
    {
        foreach ($this->getCmd($this->parse($command))->suffix[0]->parts as $p) {
            if ($p->type === $type) {
                return $p;
            }
        }

        return null;
    }

    private function findWalk(?Node $n, string $type): ?Node
    {
        if ($n === null) {
            return null;
        }
        if ($n->type === $type) {
            return $n;
        }
        foreach ($n->properties() as $v) {
            $found = $this->walkFind($v, $type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function walkFind(mixed $v, string $type): ?Node
    {
        if ($v instanceof Node) {
            return $this->findWalk($v, $type);
        }
        if (is_array($v)) {
            foreach ($v as $item) {
                $found = $this->walkFind($item, $type);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param string[] $actual */
    private function collectAce(?Node $expr, string $command, array &$actual): void
    {
        if ($expr === null) {
            return;
        }
        $this->walkAce($expr, $command, $actual);
    }

    /** @param string[] $actual */
    private function walkAce(mixed $node, string $command, array &$actual): void
    {
        if ($node instanceof Node) {
            if ($node->type === 'ArithmeticCommandExpansion') {
                $nested = $node->script->commands[0]->command;
                $actual[] = substr($command, $nested->pos, $nested->end - $nested->pos);
            }
            foreach ($node->properties() as $v) {
                $this->walkAce($v, $command, $actual);
            }
        } elseif ($node instanceof Word) {
            foreach ($node->parts ?? [] as $p) {
                $this->walkAce($p, $command, $actual);
            }
        } elseif (is_array($node)) {
            foreach ($node as $item) {
                $this->walkAce($item, $command, $actual);
            }
        }
    }

    private function findNonEchoCommand(Node $node, string $command): ?Node
    {
        $found = null;
        $walk = function ($n) use (&$walk, &$found, $command): void {
            if ($n instanceof Node) {
                if ($n->type === 'Command' && $n->name !== null
                    && substr($command, $n->name->pos, $n->name->end - $n->name->pos) !== 'echo') {
                    $found = $n;
                }
                foreach ($n->properties() as $v) {
                    $walk($v);
                }
            } elseif ($n instanceof Word) {
                foreach ($n->parts ?? [] as $p) {
                    $walk($p);
                }
            } elseif (is_array($n)) {
                foreach ($n as $item) {
                    $walk($item);
                }
            }
        };
        $walk($node);

        return $found;
    }
}
