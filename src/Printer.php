<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * Basic opinionated printer.
 *
 * Renders an AST back to Bash. Like the reference `unbash/printer`, it does not
 * preserve original whitespace or comments (except the shebang) and normalizes
 * layout. Intended for previews, not byte-exact round-trips.
 */
final class Printer
{
    private string $indent = '  ';

    public static function print(Node $node): string
    {
        return (new self())->node($node, 0);
    }

    private function pad(int $depth): string
    {
        return str_repeat($this->indent, $depth);
    }

    private function node(Node $n, int $depth): string
    {
        return match ($n->type) {
            'Script' => $this->script($n, $depth),
            'Statement' => $this->statement($n, $depth),
            'CompoundList' => $this->block($n, $depth),
            'Command' => $this->command($n),
            'Pipeline' => $this->pipeline($n, $depth),
            'AndOr' => $this->andOr($n, $depth),
            'If' => $this->ifNode($n, $depth),
            'While' => $this->whileNode($n, $depth),
            'For' => $this->forNode($n, $depth),
            'Select' => $this->selectNode($n, $depth),
            'Case' => $this->caseNode($n, $depth),
            'Subshell' => '(' . $this->inline($n->body) . ')',
            'BraceGroup' => "{\n" . $this->block($n->body, $depth + 1) . "\n" . $this->pad($depth) . '}',
            'Function' => $this->functionNode($n, $depth),
            'Coproc' => 'coproc ' . $this->node($n->body, $depth),
            'TestCommand' => '[[ ' . $this->test($n->expression) . ' ]]',
            'ArithmeticCommand' => '((' . rtrim($n->body ?? '') . '))',
            default => '',
        };
    }

    private function script(Node $n, int $depth): string
    {
        $out = '';
        if ($n->shebang !== null) {
            $out .= $n->shebang . "\n";
        }
        $out .= $this->statementsBlock($n->commands, $depth);

        return $out;
    }

    /**
     * @param Node[] $statements
     */
    private function statementsBlock(array $statements, int $depth): string
    {
        $lines = [];
        foreach ($statements as $s) {
            $lines[] = $this->pad($depth) . $this->statement($s, $depth);
        }

        return implode("\n", $lines);
    }

    private function block(Node $compoundList, int $depth): string
    {
        return $this->statementsBlock($compoundList->commands ?? [], $depth);
    }

    private function inline(Node $compoundList): string
    {
        $parts = [];
        foreach ($compoundList->commands ?? [] as $s) {
            $parts[] = $this->statement($s, 0);
        }

        return implode('; ', $parts);
    }

    private function statement(Node $n, int $depth): string
    {
        $out = $this->node($n->command, $depth);
        foreach ($n->redirects ?? [] as $r) {
            $out .= ' ' . $this->redirect($r);
        }
        if ($n->background) {
            $out .= ' &';
        }

        return $out;
    }

    private function command(Node $n): string
    {
        $tokens = [];
        foreach ($n->prefix ?? [] as $a) {
            $tokens[] = $a->text;
        }
        if ($n->name !== null) {
            $tokens[] = $n->name->text;
        }
        foreach ($n->suffix ?? [] as $w) {
            $tokens[] = $w->text;
        }
        foreach ($n->redirects ?? [] as $r) {
            $tokens[] = $this->redirect($r);
        }

        return implode(' ', $tokens);
    }

    private function redirect(Node $r): string
    {
        $out = '';
        if ($r->fileDescriptor !== null) {
            $out .= (string) $r->fileDescriptor;
        } elseif ($r->variableName !== null) {
            $out .= '{' . $r->variableName . '}';
        }
        $out .= $r->operator;
        if ($r->target !== null) {
            $out .= $r->target->text;
        }

        return $out;
    }

    private function pipeline(Node $n, int $depth): string
    {
        $out = '';
        if ($n->time) {
            $out .= 'time ';
        }
        if ($n->negated) {
            $out .= '! ';
        }
        $cmds = $n->commands;
        $ops = $n->operators;
        $out .= $this->node($cmds[0], $depth);
        for ($i = 1; $i < count($cmds); $i++) {
            $out .= ' ' . $ops[$i - 1] . ' ' . $this->node($cmds[$i], $depth);
        }

        return $out;
    }

    private function andOr(Node $n, int $depth): string
    {
        $cmds = $n->commands;
        $ops = $n->operators;
        $out = $this->node($cmds[0], $depth);
        for ($i = 1; $i < count($cmds); $i++) {
            $out .= ' ' . $ops[$i - 1] . ' ' . $this->node($cmds[$i], $depth);
        }

        return $out;
    }

    private function ifNode(Node $n, int $depth): string
    {
        $out = 'if ' . $this->inline($n->clause) . "; then\n";
        $out .= $this->block($n->then, $depth + 1);
        $else = $n->else;
        if ($else instanceof Node && $else->type === 'If') {
            $out .= "\n" . $this->pad($depth) . 'el' . ltrim($this->ifNode($else, $depth));

            return $out;
        }
        if ($else instanceof Node) {
            $out .= "\n" . $this->pad($depth) . "else\n" . $this->block($else, $depth + 1);
        }
        $out .= "\n" . $this->pad($depth) . 'fi';

        return $out;
    }

    private function whileNode(Node $n, int $depth): string
    {
        return $n->kind . ' ' . $this->inline($n->clause) . "; do\n"
            . $this->block($n->body, $depth + 1)
            . "\n" . $this->pad($depth) . 'done';
    }

    private function forNode(Node $n, int $depth): string
    {
        $words = array_map(static fn (Word $w) => $w->text, $n->wordlist ?? []);
        $head = 'for ' . $n->name->text;
        if ($words !== []) {
            $head .= ' in ' . implode(' ', $words);
        }

        return $head . "; do\n" . $this->block($n->body, $depth + 1) . "\n" . $this->pad($depth) . 'done';
    }

    private function selectNode(Node $n, int $depth): string
    {
        $words = array_map(static fn (Word $w) => $w->text, $n->wordlist ?? []);
        $head = 'select ' . $n->name->text;
        if ($words !== []) {
            $head .= ' in ' . implode(' ', $words);
        }

        return $head . "; do\n" . $this->block($n->body, $depth + 1) . "\n" . $this->pad($depth) . 'done';
    }

    private function caseNode(Node $n, int $depth): string
    {
        $out = 'case ' . $n->word->text . " in\n";
        foreach ($n->items ?? [] as $item) {
            $patterns = implode('|', array_map(static fn (Word $w) => $w->text, $item->pattern));
            $out .= $this->pad($depth + 1) . $patterns . ")\n";
            $body = $this->block($item->body, $depth + 2);
            if ($body !== '') {
                $out .= $body . "\n";
            }
            $out .= $this->pad($depth + 2) . ($item->terminator ?? ';;') . "\n";
        }
        $out .= $this->pad($depth) . 'esac';

        return $out;
    }

    private function functionNode(Node $n, int $depth): string
    {
        return $n->name->text . '() ' . $this->node($n->body, $depth);
    }

    private function test(?Node $e): string
    {
        if ($e === null) {
            return '';
        }

        return match ($e->type) {
            'TestUnary' => $e->operator === '-n' ? $e->operand->text : $e->operator . ' ' . $e->operand->text,
            'TestBinary' => $e->left->text . ' ' . $e->operator . ' ' . $e->right->text,
            'TestLogical' => $this->test($e->left) . ' ' . $e->operator . ' ' . $this->test($e->right),
            'TestNot' => '! ' . $this->test($e->operand),
            'TestGroup' => '( ' . $this->test($e->expression) . ' )',
            default => '',
        };
    }
}
