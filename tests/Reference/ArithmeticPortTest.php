<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\ArithmeticParser;
use ReactphpX\Unbash\Lexer;
use ReactphpX\Unbash\Node;

/** Ported from webpro-nl/unbash test/arithmetic.test.ts */
final class ArithmeticPortTest extends RefTestCase
{
    private function arith(string $s): ?Node
    {
        return (new ArithmeticParser(new Lexer($s)))->parse($s, 0);
    }

    public function testEmptyReturnsNull(): void
    {
        $this->assertNull($this->arith(''));
        $this->assertNull($this->arith('   '));
    }

    public function testAtoms(): void
    {
        $this->assertSame('ArithmeticWord', $this->arith('42')->type);
        $this->assertSame('42', $this->arith('42')->value);
        $this->assertSame('x', $this->arith('x')->value);
    }

    public function testBinaryOps(): void
    {
        foreach (['+', '-', '*', '/', '%', '**'] as $op) {
            $this->assertSame($op, $this->arith("x $op y")->operator, $op);
        }
        foreach (['<', '<=', '>', '>=', '==', '!='] as $op) {
            $this->assertSame($op, $this->arith("x $op y")->operator, $op);
        }
        $this->assertSame('<<', $this->arith('x << 2')->operator);
        $this->assertSame('>>', $this->arith('y >> 1')->operator);
    }

    public function testPrecedence(): void
    {
        $e = $this->arith('a + b * c');
        $this->assertSame('+', $e->operator);
        $this->assertSame('a', $e->left->value);
        $this->assertSame('*', $e->right->operator);

        $p = $this->arith('(a + b) * c');
        $this->assertSame('*', $p->operator);
        $this->assertSame('ArithmeticBinary', $p->left->expression->type);

        $la = $this->arith('a && b || c');
        $this->assertSame('||', $la->operator);
        $this->assertSame('&&', $la->left->operator);

        $bit = $this->arith('a & b | c ^ d');
        $this->assertSame('|', $bit->operator);
    }

    public function testExponentRightAssociative(): void
    {
        $e = $this->arith('2 ** 3 ** 4');
        $this->assertSame('**', $e->operator);
        $this->assertSame('2', $e->left->value);
        $this->assertSame('**', $e->right->operator);
    }

    public function testUnary(): void
    {
        foreach (['-', '+', '!', '~', '++', '--'] as $op) {
            $e = $this->arith("{$op}x");
            $this->assertSame($op, $e->operator, $op);
            $this->assertTrue($e->prefix, $op);
        }
        $post = $this->arith('x++');
        $this->assertSame('++', $post->operator);
        $this->assertFalse($post->prefix);
        $this->assertSame('x', $post->operand->value);
        $this->assertFalse($this->arith('x--')->prefix);
    }

    public function testTernary(): void
    {
        $e = $this->arith('x > y ? x : y');
        $this->assertSame('ArithmeticTernary', $e->type);
        $this->assertSame('ArithmeticBinary', $e->test->type);
        $this->assertSame('x', $e->consequent->value);
        $this->assertSame('y', $e->alternate->value);
        $nested = $this->arith('a ? b : c ? d : e');
        $this->assertSame('ArithmeticTernary', $nested->type);
        $this->assertSame('ArithmeticTernary', $nested->alternate->type);
    }

    public function testAssignmentAndComma(): void
    {
        $e = $this->arith('x = 5');
        $this->assertSame('=', $e->operator);
        $this->assertSame('x', $e->left->value);
        $this->assertSame('5', $e->right->value);
        foreach (['+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<=', '>>='] as $op) {
            $this->assertSame($op, $this->arith("x $op 5")->operator, $op);
        }
        $ra = $this->arith('a = b = c');
        $this->assertSame('=', $ra->operator);
        $this->assertSame('=', $ra->right->operator);
        $comma = $this->arith('a = 1, b = 2');
        $this->assertSame(',', $comma->operator);
        $this->assertSame('=', $comma->left->operator);
        $this->assertSame('=', $comma->right->operator);
    }

    public function testLiterals(): void
    {
        $this->assertSame('0xFF', $this->arith('0xFF')->value);
        $this->assertSame('0777', $this->arith('0777')->value);
        $this->assertSame('2#10101010', $this->arith('2#10101010')->value);
    }

    public function testDollarAtoms(): void
    {
        $e = $this->arith('$x + 1');
        $this->assertSame('+', $e->operator);
        $this->assertSame('$x', $e->left->value);

        $cmd = $this->arith('$(cmd) + 1');
        $this->assertSame('ArithmeticBinary', $cmd->type);
        $this->assertSame('ArithmeticCommandExpansion', $cmd->left->type);
        $this->assertSame('$(cmd)', $cmd->left->text);

        foreach ([['${x:-1} + 2', '${x:-1}'], ['$((1+2)) + 3', '$((1+2))']] as [$source, $value]) {
            $ex = $this->arith($source);
            $this->assertSame('ArithmeticBinary', $ex->type, $source);
            $this->assertSame('ArithmeticWord', $ex->left->type, $source);
            $this->assertSame($value, $ex->left->value, $source);
        }
    }

    public function testArraySubscript(): void
    {
        $e = $this->arith('arr[i] + 1');
        $this->assertSame('+', $e->operator);
        $this->assertSame('arr[i]', $e->left->value);
    }

    public function testArraySubscriptStructuredCommandSubstitutions(): void
    {
        $src = 'echo $((arr[1+$(one)$(two)]))';
        $expansion = $this->getCmd($this->parse($src))->suffix[0]->parts[0];
        $this->assertSame('ArithmeticExpansion', $expansion->type);
        $this->assertSame('ArithmeticWord', $expansion->expression->type);
        $names = [];
        foreach ($expansion->expression->parts as $p) {
            if ($p->type === 'CommandExpansion') {
                $names[] = $p->script->commands[0]->command->name->value;
            }
        }
        $this->assertSame(['one', 'two'], $names);
    }

    public function testAdjacentArithmeticCommandSubstitutions(): void
    {
        foreach (['$(one)$(two)', '$(one)x$(two)', 'x$(one)', 'array[0]$(one)'] as $body) {
            $src = "echo \$(( $body ))";
            $expansion = $this->getCmd($this->parse($src))->suffix[0]->parts[0];
            $this->assertSame('ArithmeticExpansion', $expansion->type, $body);
            $this->assertSame('ArithmeticWord', $expansion->expression->type, $body);
            $names = [];
            foreach ($expansion->expression->parts as $p) {
                if ($p->type === 'CommandExpansion') {
                    $names[] = $p->script->commands[0]->command->name->value;
                }
            }
            $this->assertSame(str_contains($body, 'two') ? ['one', 'two'] : ['one'], $names, $body);
        }
    }

    public function testLegacyBackticksInArithmetic(): void
    {
        $src = 'echo $((`danger` + 1))';
        $expansion = $this->getCmd($this->parse($src))->suffix[0]->parts[0];
        $this->assertSame('ArithmeticExpansion', $expansion->type);
        $this->assertSame('ArithmeticBinary', $expansion->expression->type);
    }

    public function testArithmeticExpansionInCommand(): void
    {
        $expansion = $this->getCmd($this->parse('echo $((1 + 2))'))->suffix[0]->parts[0];
        $this->assertSame('ArithmeticExpansion', $expansion->type);
        $this->assertSame('ArithmeticBinary', $expansion->expression->type);
        $this->assertSame('+', $expansion->expression->operator);
    }

    public function testArithmeticCommand(): void
    {
        $ac = $this->parse('(( x = 1 + 2 * 3 ))')->commands[0]->command;
        $this->assertSame('ArithmeticCommand', $ac->type);
        $this->assertSame('=', $ac->expression->operator);
        $this->assertSame('+', $ac->expression->right->operator);
    }

    public function testCStyleForExpressions(): void
    {
        $af = $this->parse('for (( i = 0; i < 10; i++ )); do echo $i; done')->commands[0]->command;
        $this->assertSame('ArithmeticFor', $af->type);
        $this->assertSame('=', $af->initialize->operator);
        $this->assertSame('<', $af->test->operator);
        $this->assertSame('++', $af->update->operator);
    }
}
