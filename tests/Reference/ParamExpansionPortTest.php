<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

/** Ported from webpro-nl/unbash test/param-expansion.test.ts */
final class ParamExpansionPortTest extends RefTestCase
{
    private function part(string $input): Node
    {
        return $this->getCmd($this->parse($input))->suffix[0]->parts[0];
    }

    private function quotedParam(Word $word): Node
    {
        return $word->parts[0]->parts[0];
    }

    public function testSimpleVar(): void
    {
        $p = $this->part('echo ${var}');
        $this->assertSame('ParameterExpansion', $p->type);
        $this->assertSame('var', $p->parameter);
        $this->assertSame('${var}', $p->text);
        $this->assertNull($p->operator);
        $this->assertNull($p->indirect);
        $this->assertNull($p->length);
    }

    public function testSpecialVars(): void
    {
        $this->assertSame('#', $this->part('echo ${#}')->parameter);
        $this->assertNull($this->part('echo ${#}')->length);
        $this->assertSame('@', $this->part('echo ${@}')->parameter);
        $this->assertSame('?', $this->part('echo ${?}')->parameter);
    }

    public function testColonOperators(): void
    {
        foreach ([
            ['echo ${var:-default}', ':-', 'default'],
            ['echo ${var:=assigned}', ':=', 'assigned'],
            ['echo ${var:+alternate}', ':+', 'alternate'],
            ['echo ${var:?error msg}', ':?', 'error msg'],
        ] as [$src, $op, $operand]) {
            $p = $this->part($src);
            $this->assertSame('var', $p->parameter, $src);
            $this->assertSame($op, $p->operator, $src);
            $this->assertSame($operand, $p->operand->text, $src);
        }
    }

    public function testSemicolonInOperand(): void
    {
        foreach ([['${a:+$a;}', 9, 8, ';'], ['${a:+$a; }', 10, 9, '; ']] as [$source, $end, $operandEnd, $literal]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($end, $ast->end, $source);
            $word = $this->getCmd($ast)->name;
            $this->assertSame($source, $word->text, $source);
            $part = $word->parts[0];
            $this->assertSame('ParameterExpansion', $part->type, $source);
            $this->assertSame('a', $part->parameter, $source);
            $this->assertSame(':+', $part->operator, $source);
            $this->assertSame([5, $operandEnd, '$a' . $literal], [$part->operand->pos, $part->operand->end, $part->operand->text], $source);
            $this->assertEquals([
                ['type' => 'SimpleExpansion', 'text' => '$a'],
                ['type' => 'Literal', 'value' => $literal, 'text' => $literal],
            ], $this->partsArr($part->operand), $source);
        }
    }

    public function testNoColonOperators(): void
    {
        foreach ([
            ['echo ${var-default}', '-', 'default'],
            ['echo ${var=default}', '=', 'default'],
            ['echo ${var+alt}', '+', 'alt'],
            ['echo ${var?err}', '?', 'err'],
        ] as [$src, $op, $operand]) {
            $p = $this->part($src);
            $this->assertSame('var', $p->parameter, $src);
            $this->assertSame($op, $p->operator, $src);
            $this->assertSame($operand, $p->operand->text, $src);
        }
    }

    public function testLength(): void
    {
        $p = $this->part('echo ${#var}');
        $this->assertSame('var', $p->parameter);
        $this->assertTrue($p->length);
        $this->assertNull($p->operator);
        $p2 = $this->part('echo ${#arr[@]}');
        $this->assertSame('arr', $p2->parameter);
        $this->assertSame('@', $p2->index);
        $this->assertTrue($p2->length);
    }

    public function testPrefixSuffixStrip(): void
    {
        foreach ([
            ['echo ${path#*/}', '#', '*/'],
            ['echo ${path##*/}', '##', '*/'],
            ['echo ${path%/*}', '%', '/*'],
            ['echo ${path%%/*}', '%%', '/*'],
        ] as [$src, $op, $operand]) {
            $p = $this->part($src);
            $this->assertSame('path', $p->parameter, $src);
            $this->assertSame($op, $p->operator, $src);
            $this->assertSame($operand, $p->operand->text, $src);
        }
    }

    public function testEscapedBracesStripOperands(): void
    {
        $source = "name=\"\${value#\\{sd.cicd.}\"\necho \"\${name%\\}}\"";
        $ast = $this->parse($source);
        $operands = [$this->quotedParam($this->getCmd($ast)->prefix[0]->value), $this->quotedParam($this->getCmd($ast, 1)->suffix[0])];
        $this->assertNull($ast->errors);
        $this->assertSame([
            ['#', '\{sd.cicd.', '{sd.cicd.', 14, 24],
            ['%', '\}', '}', 40, 42],
        ], array_map(fn ($p) => [$p->operator, $p->operand->text, $p->operand->value, $p->operand->pos, $p->operand->end], $operands));
    }

    public function testQuotedExpansionInSuffixPattern(): void
    {
        $source = 'echo "${1%"$2"*}"';
        $ast = $this->parse($source);
        $part = $this->quotedParam($this->getCmd($ast)->suffix[0]);
        $this->assertNull($ast->errors);
        $this->assertSame(['1', '%', '"$2"*', '$2*', 10, 15], [$part->parameter, $part->operator, $part->operand->text, $part->operand->value, $part->operand->pos, $part->operand->end]);
        $this->assertEquals([
            ['type' => 'DoubleQuoted', 'text' => '"$2"', 'parts' => [['type' => 'SimpleExpansion', 'text' => '$2']]],
            ['type' => 'Literal', 'value' => '*', 'text' => '*'],
        ], $this->partsArr($part->operand));
    }

    public function testReplacement(): void
    {
        $p = $this->part('echo ${version/beta/rc}');
        $this->assertSame('version', $p->parameter);
        $this->assertSame('/', $p->operator);
        $this->assertSame('beta', $p->replace['pattern']->text);
        $this->assertSame('rc', $p->replace['replacement']->text);

        $p2 = $this->part('echo ${version//./,}');
        $this->assertSame('//', $p2->operator);
        $this->assertSame('.', $p2->replace['pattern']->text);
        $this->assertSame(',', $p2->replace['replacement']->text);

        $p3 = $this->part('echo ${paths/#/-i }');
        $this->assertSame('/#', $p3->operator);
        $this->assertSame('', $p3->replace['pattern']->text);
        $this->assertSame('-i ', $p3->replace['replacement']->text);

        $p4 = $this->part('echo ${paths/%/-end}');
        $this->assertSame('/%', $p4->operator);
        $this->assertSame('', $p4->replace['pattern']->text);
        $this->assertSame('-end', $p4->replace['replacement']->text);

        $p5 = $this->part('echo ${pv/\.}');
        $this->assertSame('/', $p5->operator);
        $this->assertSame('\.', $p5->replace['pattern']->text);
        $this->assertSame('', $p5->replace['replacement']->text);
    }

    public function testSlices(): void
    {
        $p = $this->part('echo ${var:0:5}');
        $this->assertSame('0', $p->slice['offset']->text);
        $this->assertSame('5', $p->slice['length']->text);
        $this->assertSame('6', $this->part('echo ${var:6}')->slice['offset']->text);
        $this->assertNull($this->part('echo ${var:6}')->slice['length']);
        $pn = $this->part('echo ${PN::-1}');
        $this->assertSame('', $pn->slice['offset']->text);
        $this->assertSame('-1', $pn->slice['length']->text);
        $this->assertSame(' -1', $this->part('echo ${parameter: -1}')->slice['offset']->text);
        $this->assertSame('(-1)', $this->part('echo ${parameter:(-1)}')->slice['offset']->text);
    }

    public function testTernaryInSlice(): void
    {
        $cases = [
            ['echo ${FOO: 0 : 1 ? 2 : 3}', [' 0 ', 11, 14], [' 1 ? 2 : 3', 15, 25]],
            ['echo ${FOO: 1 ? 2 : 3 : 4}', [' 1 ? 2 : 3 ', 11, 22], [' 4', 23, 25]],
            ['echo ${FOO: 1 ? 2 ? 3 : 4 : 5 : 2}', [' 1 ? 2 ? 3 : 4 : 5 ', 11, 30], [' 2', 31, 33]],
            ['echo ${FOO:(1?2:3):2}', ['(1?2:3)', 11, 18], ['2', 19, 20]],
        ];
        foreach ($cases as [$source, $expOffset, $expLength]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $slice = $this->getCmd($ast)->suffix[0]->parts[0]->slice;
            $this->assertSame($expOffset, [$slice['offset']->text, $slice['offset']->pos, $slice['offset']->end], $source);
            $this->assertSame($expLength, [$slice['length']->text, $slice['length']->pos, $slice['length']->end], $source);
        }
    }

    public function testCaseModification(): void
    {
        $this->assertSame('^', $this->part('echo ${var^}')->operator);
        $this->assertSame('^^', $this->part('echo ${var^^}')->operator);
        $this->assertSame(',', $this->part('echo ${var,}')->operator);
        $this->assertSame(',,', $this->part('echo ${var,,}')->operator);
        $this->assertSame('[I]', $this->part('echo ${H,,[I]}')->operand->text);
        $this->assertSame(',,', $this->part('echo ${H,,[I]}')->operator);
    }

    public function testTransform(): void
    {
        foreach (['Q', 'E', 'A'] as $t) {
            $p = $this->part("echo \${var@$t}");
            $this->assertSame('@', $p->operator);
            $this->assertSame($t, $p->operand->text);
        }
    }

    public function testArray(): void
    {
        $this->assertSame('@', $this->part('echo ${arr[@]}')->index);
        $this->assertSame('*', $this->part('echo ${arr[*]}')->index);
        $this->assertSame('0', $this->part('echo ${arr[0]}')->index);
        $this->assertSame('name', $this->part('echo ${map[name]}')->index);
        $slice = $this->part('echo ${arr[@]:2:3}');
        $this->assertSame('@', $slice->index);
        $this->assertSame('2', $slice->slice['offset']->text);
        $this->assertSame('3', $slice->slice['length']->text);
        $this->assertSame('^^', $this->part('echo ${arr[@]^^}')->operator);
        $rep = $this->part('echo ${arr[@]/a/A}');
        $this->assertSame('/', $rep->operator);
        $this->assertSame('a', $rep->replace['pattern']->text);
        $strip = $this->part('echo ${arr[@]%o*}');
        $this->assertSame('%', $strip->operator);
        $this->assertSame('o*', $strip->operand->text);
    }

    public function testIndexKeepsCommandSubstitution(): void
    {
        $p = $this->part('echo ${arr[1+$(danger)]}');
        $this->assertSame('1+$(danger)', $p->index);
        $expansion = null;
        foreach ($p->indexParts as $part) {
            if ($part->type === 'CommandExpansion') {
                $expansion = $part;
            }
        }
        $this->assertSame('danger', $expansion->script->commands[0]->command->name->value);
    }

    public function testIndexQuotedClosingBracket(): void
    {
        $p = $this->part('echo ${#arr[$(printf "]")]}');
        $this->assertSame('$(printf "]")', $p->index);
    }

    public function testIndirect(): void
    {
        $this->assertTrue($this->part('echo ${!var}')->indirect);
        $this->assertSame('var', $this->part('echo ${!var}')->parameter);
        $prefix = $this->part('echo ${!BASH*}');
        $this->assertSame('BASH', $prefix->parameter);
        $this->assertTrue($prefix->indirect);
        $keys = $this->part('echo ${!map[@]}');
        $this->assertSame('map', $keys->parameter);
        $this->assertSame('@', $keys->index);
        $this->assertTrue($keys->indirect);
        $last = $this->part('echo ${!#}');
        $this->assertSame('#', $last->parameter);
        $this->assertTrue($last->indirect);
    }

    public function testHashEdgeCases(): void
    {
        $this->assertSame('#', $this->part('echo ${#}')->parameter);
        $this->assertNull($this->part('echo ${#}')->length);
        $p = $this->part('echo ${##}');
        $this->assertSame('#', $p->parameter);
        $this->assertSame('#', $p->operator);
        $this->assertSame('', $p->operand->text);
        $p2 = $this->part('echo ${##/}');
        $this->assertSame('#', $p2->parameter);
        $this->assertSame('#', $p2->operator);
        $this->assertSame('/', $p2->operand->text);
    }

    public function testStructuredOperands(): void
    {
        $this->assertSame(' ', $this->part('echo ${abc:- }')->operand->text);
        $p = $this->part('echo ${B[0]# }');
        $this->assertSame('0', $p->index);
        $this->assertSame('#', $p->operator);
        $this->assertSame(' ', $p->operand->text);
        $this->assertSame('*=', $this->part('echo ${p_key#*=}')->operand->text);
        $this->assertSame('${var:-default}', $this->part('echo ${var:-default}')->text);

        $nested = $this->part('echo ${var:-${other:-fallback}}');
        $this->assertSame('${other:-fallback}', $nested->operand->text);
        $inner = $nested->operand->parts[0];
        $this->assertSame('ParameterExpansion', $inner->type);
        $this->assertSame('other', $inner->parameter);

        $dq = $this->part('echo ${var:-"default $value"}');
        $this->assertSame('DoubleQuoted', $dq->operand->parts[0]->type);
        $cs = $this->part('echo ${var:-$(whoami)}');
        $this->assertSame('CommandExpansion', $cs->operand->parts[0]->type);
        $this->assertNotNull($cs->operand->parts[0]->script);
        $se = $this->part('echo ${var:-$HOME/bin}');
        $this->assertSame('$HOME/bin', $se->operand->text);
        $this->assertSame('SimpleExpansion', $se->operand->parts[0]->type);
    }

    public function testExpansionsInReplaceAndSlice(): void
    {
        $rp = $this->part('echo ${var//$pat/rep}');
        $this->assertSame('SimpleExpansion', $rp->replace['pattern']->parts[0]->type);
        $rr = $this->part('echo ${var//old/$new}');
        $this->assertSame('SimpleExpansion', $rr->replace['replacement']->parts[0]->type);
        $sl = $this->part('echo ${var:0:${#var}}');
        $this->assertSame('0', $sl->slice['offset']->text);
        $this->assertSame('ParameterExpansion', $sl->slice['length']->parts[0]->type);
    }

    public function testEmptyAndPlainOperands(): void
    {
        $this->assertSame('', $this->part('echo ${var:-}')->operand->text);
        $this->assertNull($this->part('echo ${var:-}')->operand->parts);
        $this->assertSame('default', $this->part('echo ${var:-default}')->operand->text);
        $this->assertNull($this->part('echo ${var:-default}')->operand->parts);
        $deep = $this->part('echo ${a:-${b:-${c}}}');
        $b = $deep->operand->parts[0];
        $this->assertSame('b', $b->parameter);
        $this->assertSame('c', $b->operand->parts[0]->parameter);
    }

    public function testReplaceWithQuotedPattern(): void
    {
        $p = $this->part("echo \${f%'-roff2html'*}");
        $this->assertSame('%', $p->operator);
        $this->assertSame("'-roff2html'*", $p->operand->text);
        $this->assertSame('-roff2html*', $p->operand->value);
        $this->assertSame('SingleQuoted', $p->operand->parts[0]->type);
    }

    public function testBulkParse(): void
    {
        foreach ([
            'echo ${var1#*#}', 'echo ${!abc}', 'echo ${abc:-def}', 'echo ${abc:+ghi}',
            'echo ${abc,?}', 'echo ${abc^^b}', 'echo ${abc@U}', 'F="${G%% *}"',
            "A=\${B//:;;/\$'\\n'}", 'echo "${kw}? ( ${cond:+${cond}? (} ${baseuri}-${ver} ${cond:+) })"',
            'echo "${IMAGE,,}"',
        ] as $script) {
            $this->assertGreaterThan(0, count($this->parse($script)->commands), $script);
        }
    }

    public function testScansOverNestedCommandSubstitutions(): void
    {
        foreach ([
            ['echo ${x:-$(<})}', '${x:-$(<})}'],
            ['echo ${foo:-$({ ls /bin/ls; })}', '${foo:-$({ ls /bin/ls; })}'],
            ['echo ${x:-$(echo })}', '${x:-$(echo })}'],
            ['echo ${x:-`echo }`}', '${x:-`echo }`}'],
            ['echo ${x#$({ a; })}', '${x#$({ a; })}'],
            ['echo ${x:-$((1+2))}', '${x:-$((1+2))}'],
        ] as [$source, $text]) {
            $command = $this->getCmd($this->parse($source));
            $this->assertSame($text, $command->suffix[0]->text, $source);
            $this->assertSame('ParameterExpansion', $command->suffix[0]->parts[0]->type, $source);
            $this->assertNull($this->parse($source)->errors, $source);
        }
    }

    public function testUnterminatedSubstitutionInsideExpansion(): void
    {
        $this->assertNotNull($this->parse('echo ${x:-$(a}')->errors);
    }
}
