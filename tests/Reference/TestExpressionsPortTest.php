<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;

/** Ported from webpro-nl/unbash test/test-expressions.test.ts */
final class TestExpressionsPortTest extends RefTestCase
{
    private function getTest(string $src): Node
    {
        $node = $this->parse($src)->commands[0]->command;
        $this->assertSame('TestCommand', $node->type, $src);

        return $node;
    }

    private function partShape(Node $part): array
    {
        return match ($part->type) {
            'ExtendedGlob' => [$part->type, $part->text, $part->operator, $part->pattern],
            'DoubleQuoted' => [$part->type, $part->text, array_map([$this, 'partShape'], $part->parts)],
            'Literal', 'SingleQuoted' => [$part->type, $part->text, $part->value],
            default => [$part->type, $part->text],
        };
    }

    public function testUnary(): void
    {
        $t = $this->getTest('[[ -f $file ]]');
        $this->assertSame('TestUnary', $t->expression->type);
        $this->assertSame('-f', $t->expression->operator);
        $this->assertSame('$file', $t->expression->operand->text);
        foreach (['-d', '-z', '-n', '-e', '-r', '-w', '-x', '-s', '-L', '-S', '-b', '-c', '-p', '-t', '-v'] as $op) {
            $this->assertSame($op, $this->getTest("[[ $op x ]]")->expression->operator);
        }
    }

    public function testUnaryOShellOption(): void
    {
        $t = $this->getTest('[[ -o emacs && -v b ]]');
        $this->assertSame('&&', $t->expression->operator);
        $this->assertSame('-o', $t->expression->left->operator);
        $this->assertSame('emacs', $t->expression->left->operand->text);
    }

    public function testBinary(): void
    {
        $t = $this->getTest('[[ $str == hello ]]');
        $this->assertSame('TestBinary', $t->expression->type);
        $this->assertSame('==', $t->expression->operator);
        $this->assertSame('$str', $t->expression->left->text);
        $this->assertSame('hello', $t->expression->right->text);
        foreach (['!=', '=', '-eq', '-ne', '-lt', '-le', '-gt', '-ge', '-nt', '-ot', '-ef'] as $op) {
            $this->assertSame($op, $this->getTest("[[ \$a $op \$b ]]")->expression->operator, $op);
        }
        $this->assertSame('<', $this->getTest('[[ $str < world ]]')->expression->operator);
        $this->assertSame('>', $this->getTest('[[ $str > aaa ]]')->expression->operator);
    }

    public function testAnsiCClosingBracketsOperand(): void
    {
        $ast = $this->parse("[[ value == \$']]' ]]");
        $t = $ast->commands[0]->command;
        $this->assertSame('TestCommand', $t->type);
        $this->assertSame(']]', $t->expression->right->value);
        $this->assertNull($ast->errors);
    }

    public function testQuotedBangIsOperand(): void
    {
        foreach (["[[ '!' == x ]]", '[[ "!" == x ]]', '[[ \! == x ]]'] as $source) {
            $t = $this->getTest($source);
            $this->assertSame('TestBinary', $t->expression->type, $source);
            $this->assertSame('==', $t->expression->operator, $source);
            $this->assertSame('!', $t->expression->left->value, $source);
        }
    }

    public function testUnquotedBangNegates(): void
    {
        $this->assertSame('TestNot', $this->getTest('[[ ! -f /etc/hosts ]]')->expression->type);
        $doubled = $this->getTest('[[ ! ! -f /etc/hosts ]]')->expression;
        $this->assertSame('TestNot', $doubled->type);
        $this->assertSame('TestNot', $doubled->operand->type);
    }

    public function testDoubleBracketOutsideIsWord(): void
    {
        $echoed = $this->parse('echo ]]');
        $this->assertSame(']]', implode(',', array_map(fn ($w) => $w->value, $this->getCmd($echoed)->suffix)));
        $this->assertNull($echoed->errors);
        $this->assertSame('For', $this->parse('for i in ]] a; do echo $i; done')->commands[0]->command->type);
        $this->assertSame('Case', $this->parse('case ]] in ]]) echo m;; esac')->commands[0]->command->type);
    }

    public function testDoubleBracketAtCommandStart(): void
    {
        $ast = $this->parse(']]');
        $this->assertEquals([['message' => "unexpected token ']]'", 'pos' => 0]], $this->errorsArr($ast));
    }

    public function testRegexBasics(): void
    {
        foreach ([
            ['[[ $str =~ ^[a-z]+$ ]]', '^[a-z]+$'],
            ['[[ $str =~ ^([a-z]+)([0-9]*)$ ]]', '^([a-z]+)([0-9]*)$'],
            ['[[ $str =~ (hello|world) ]]', '(hello|world)'],
            ['[[ $file =~ /etc/(.*) ]]', '/etc/(.*)'],
        ] as [$source, $text]) {
            $t = $this->getTest($source);
            $this->assertSame('=~', $t->expression->operator, $source);
            $this->assertSame($text, $t->expression->right->text, $source);
        }
    }

    public function testRegexSpacesInGroups(): void
    {
        foreach (['[[ a =~ [ab](c |d) ]]', '[[ a =~ ( ]]<>;&) ]]'] as $source) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $t = $ast->commands[0]->command;
            $this->assertSame(substr($source, 8, -3), $t->expression->right->text, $source);
        }
        $t = $this->getTest("[[ 'a b' =~ (a b) ]]");
        $this->assertSame('(a b)', $t->expression->right->text);
        $this->assertSame('(a b)', $t->expression->right->value);
    }

    public function testRegexParamExpansionSpaces(): void
    {
        foreach ([
            ['[[ x =~ ${v/ /.} ]]', '${v/ /.}'],
            ['[[ x =~ ${v/ /.}z ]]', '${v/ /.}z'],
            ['[[ x =~ ${v:-)} ]]', '${v:-)}'],
        ] as [$source, $text]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($text, $ast->commands[0]->command->expression->right->text, $source);
        }
    }

    public function testRegexSplitsAtLogical(): void
    {
        $t = $this->getTest('[[ ab =~ (a)&&(zzz) ]]');
        $this->assertSame('TestLogical', $t->expression->type);
        $l = $t->expression;
        $this->assertSame('&&', $l->operator);
        $this->assertSame('=~', $l->left->operator);
        $this->assertSame('(a)', $l->left->right->text);
        $this->assertSame('TestGroup', $l->right->type);
    }

    public function testRegexEndsAtGroupClose(): void
    {
        foreach ([
            ['[[ (ab =~ a) ]]', 'a'],
            ['[[ ( ab =~ a) ]]', 'a'],
            ['[[ ( ab =~ (a)) ]]', '(a)'],
        ] as [$source, $text]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $t = $ast->commands[0]->command;
            $this->assertSame('TestGroup', $t->expression->type, $source);
            $this->assertSame($text, $t->expression->expression->right->text, $source);
        }
    }

    public function testRegexEndsAtDelimiters(): void
    {
        foreach ([
            ['[[ ab =~ a) ]]', 'a'],
            ['[[ ab =~ (a)b) ]]', '(a)b'],
            ["[[ 'a<b' =~ a<b ]]", 'a'],
            ['[[ x =~ a;b ]]', 'a'],
        ] as [$source, $text]) {
            $ast = $this->parse($source);
            $this->assertNotNull($ast->errors, $source);
            $this->assertSame($text, $ast->commands[0]->command->expression->right->text, $source);
        }
    }

    public function testRegexProcessSubstitution(): void
    {
        foreach ([
            ['[[ x =~ <(y) ]]', '<(y)'],
            ['[[ x =~ a<(b) ]]', 'a<(b)'],
            ['[[ x =~ a>(b) ]]', 'a>(b)'],
        ] as [$source, $text]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($text, $ast->commands[0]->command->expression->right->text, $source);
        }
    }

    public function testRegexCommandSubstitutionKeepsCaseArms(): void
    {
        $this->markTestSkipped('case-`)` pattern extent scanning inside $() is not modeled.');
    }

    public function testRegexGroupedExpansions(): void
    {
        $ast = $this->parse('[[ x =~ (a $(danger) $HOME b) ]]');
        $this->assertNull($ast->errors);
        $right = $ast->commands[0]->command->expression->right;
        $hasSimple = false;
        $ce = null;
        foreach ($right->parts as $p) {
            if ($p->type === 'SimpleExpansion') {
                $hasSimple = true;
            }
            if ($p->type === 'CommandExpansion') {
                $ce = $p;
            }
        }
        $this->assertTrue($hasSimple);
        $this->assertSame('danger', $ce->script->commands[0]->command->name->value);
    }

    public function testLogical(): void
    {
        $t = $this->getTest('[[ -f $file && -r $file ]]');
        $this->assertSame('TestLogical', $t->expression->type);
        $this->assertSame('&&', $t->expression->operator);
        $this->assertSame('||', $this->getTest('[[ -d $dir || -f $dir ]]')->expression->operator);

        $chain = $this->getTest('[[ -f $file && -r $file && -s $file ]]');
        $this->assertSame('&&', $chain->expression->operator);
        $this->assertSame('&&', $chain->expression->left->operator);
        $this->assertSame('-s', $chain->expression->right->operator);

        $prec = $this->getTest('[[ $str == hello && $num -eq 42 || $empty == "" ]]');
        $this->assertSame('||', $prec->expression->operator);
        $this->assertSame('&&', $prec->expression->left->operator);
    }

    public function testNegationAndGrouping(): void
    {
        $t = $this->getTest('[[ ! -z $str ]]');
        $this->assertSame('TestNot', $t->expression->type);
        $this->assertSame('-z', $t->expression->operand->operator);

        $ng = $this->getTest('[[ ! ( -z $str || -z $file ) ]]');
        $this->assertSame('TestNot', $ng->expression->type);
        $this->assertSame('TestGroup', $ng->expression->operand->type);

        $g = $this->getTest('[[ ( -f $file || -d $file ) && -r $file ]]');
        $this->assertSame('&&', $g->expression->operator);
        $this->assertSame('TestGroup', $g->expression->left->type);
        $this->assertSame('||', $g->expression->left->expression->operator);
    }

    public function testStandaloneWord(): void
    {
        $t = $this->getTest('[[ $str ]]');
        $this->assertSame('TestUnary', $t->expression->type);
        $this->assertSame('-n', $t->expression->operator);
        $this->assertSame('$str', $t->expression->operand->text);
    }

    public function testPatterns(): void
    {
        $this->assertSame('h*', $this->getTest('[[ $str == h* ]]')->expression->right->text);
        $this->assertSame('[Hh]ello', $this->getTest('[[ $str == [Hh]ello ]]')->expression->right->text);
    }

    public function testPrefixedExtglobs(): void
    {
        $ast = $this->parse('[[ "a.md" == ?.@(md|mkd) ]]');
        $this->assertNull($ast->errors);
        $right = $ast->commands[0]->command->expression->right;
        $this->assertSame([13, 24], [$right->pos, $right->end]);
        $this->assertSame([
            ['Literal', '?.', '?.'],
            ['ExtendedGlob', '@(md|mkd)', '@', 'md|mkd'],
        ], array_map([$this, 'partShape'], $right->parts));
    }

    public function testQuotedSpaceGlob(): void
    {
        $source = "if [[ \"\${tmp}\" = *\"A B\"* ]]; then\n  echo \"ok\"\nfi\n";
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $right = $ast->commands[0]->command->clause->commands[0]->command->expression->right;
        $this->assertSame(['*"A B"*', '*A B*', 17, 24], [$right->text, $right->value, $right->pos, $right->end]);
        $this->assertSame([
            ['Literal', '*', '*'],
            ['DoubleQuoted', '"A B"', [['Literal', 'A B', 'A B']]],
            ['Literal', '*', '*'],
        ], array_map([$this, 'partShape'], $right->parts));
    }

    public function testIntegration(): void
    {
        $this->assertSame('If', $this->parse('if [[ -f $file ]]; then echo found; fi')->commands[0]->command->type);
        $this->assertSame('While', $this->parse('while [[ $n -gt 0 ]]; do echo $n; done')->commands[0]->command->type);
        $this->assertSame('AndOr', $this->parse('[[ -f $file ]] && echo exists')->commands[0]->command->type);
        $stmt = $this->parse('[[ -f $file ]] 2>/dev/null')->commands[0];
        $this->assertSame('TestCommand', $stmt->command->type);
        $this->assertCount(1, $stmt->redirects);
    }

    public function testUnaryAtEndIsStandalone(): void
    {
        $t = $this->getTest('[[ -f ]]');
        $this->assertSame('TestUnary', $t->expression->type);
        $this->assertSame('-n', $t->expression->operator);
        $this->assertSame('-f', $t->expression->operand->text);
    }

    public function testMultipleTestCommands(): void
    {
        $ast = $this->parse("[[ -f a ]]\n[[ -d b ]]");
        $this->assertCount(2, $ast->commands);
        $this->assertSame('TestCommand', $ast->commands[0]->command->type);
        $this->assertSame('TestCommand', $ast->commands[1]->command->type);
    }

    public function testDoubleBracketDoesNotEatLtRedirection(): void
    {
        $expr = $this->parse('[[ "$a" < "$b" ]] && echo less')->commands[0]->command;
        $this->assertSame(['&&'], $expr->operators);
        $this->assertSame('TestCommand', $expr->commands[0]->type);
        $this->assertSame('echo', $expr->commands[1]->name->text);
    }

    public function testProcessSubstitutionInTest(): void
    {
        foreach ([['[[ -f <(echo x) ]]', '<(echo x)'], ['[[ -f >(cat) ]]', '>(cat)']] as [$source, $text]) {
            $tc = $this->getTest($source);
            $this->assertNull($this->parse($source)->errors, $source);
            $operand = $tc->expression->operand;
            $this->assertSame($text, $operand->text, $source);
            $this->assertSame(['ProcessSubstitution'], array_map(fn ($p) => $p->type, $operand->parts), $source);
        }
        $tc = $this->getTest('[[ <(a) == <(b) ]]');
        $this->assertSame('==', $tc->expression->operator);
        $this->assertSame('<(a)', $tc->expression->left->text);
        $this->assertSame('<(b)', $tc->expression->right->text);
    }

    public function testComparisonVsProcessSubstitution(): void
    {
        foreach (['[[ a < b ]]', '[[ a <b ]]', '[[ a > b ]]'] as $source) {
            $tc = $this->getTest($source);
            $this->assertNull($this->parse($source)->errors, $source);
            $this->assertSame('a', $tc->expression->left->text, $source);
            $this->assertSame('b', $tc->expression->right->text, $source);
        }
        foreach (['[[ a <(b) ]]', '[[ a < (b) ]]'] as $source) {
            $this->assertNotNull($this->parse($source)->errors, $source);
        }
    }

    public function testConditionalOperatorsOnlyAsWritten(): void
    {
        foreach ([
            ['[[ -f /etc/hosts ]]', 'TestUnary:-f'],
            ["[[ '-f' == \$v ]]", 'TestBinary:=='],
            ['[[ "-f" == $v ]]', 'TestBinary:=='],
            ['[[ \-f == $v ]]', 'TestBinary:=='],
            ['[[ $op == $v ]]', 'TestBinary:=='],
            ["[[ '-f' ]]", 'TestUnary:-n'],
            ['[[ a < b ]]', 'TestBinary:<'],
            ['[[ a == a ]]', 'TestBinary:=='],
            ['[[ 1 -eq 1 ]]', 'TestBinary:-eq'],
        ] as [$source, $shape]) {
            $expr = $this->parse($source)->commands[0]->command->expression;
            $this->assertSame($shape, "{$expr->type}:{$expr->operator}", $source);
            $this->assertNull($this->parse($source)->errors, $source);
        }
    }

    public function testQuotedBinaryOperatorIsError(): void
    {
        $this->assertNotNull($this->parse("[[ a '==' a ]]")->errors);
        $this->assertNotNull($this->parse('[[ a "-eq" a ]]')->errors);
    }
}
