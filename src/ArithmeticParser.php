<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * Precedence-climbing parser for Bash arithmetic expressions, as used inside
 * `$(( ))`, `(( ))`, C-style `for` headers, and array indexes.
 *
 * Produces the arithmetic AST ({@see Node} types `ArithmeticBinary`,
 * `ArithmeticUnary`, `ArithmeticTernary`, `ArithmeticGroup`, `ArithmeticWord`,
 * `ArithmeticCommandExpansion`). Positions are absolute byte offsets.
 *
 * @internal
 */
final class ArithmeticParser
{
    private Lexer $lexer;
    private string $s;
    private int $base;
    private int $i;
    private int $n;

    /** Binary operators grouped by ascending precedence. */
    private const PRECEDENCE = [
        ['||'],
        ['&&'],
        ['|'],
        ['^'],
        ['&'],
        ['==', '!='],
        ['<=', '>=', '<', '>'],
        ['<<', '>>'],
        ['+', '-'],
        ['*', '/', '%'],
        ['**'],
    ];

    private const ASSIGN_OPS = ['=', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<=', '>>='];

    public function __construct(Lexer $lexer)
    {
        $this->lexer = $lexer;
    }

    public function parse(string $inner, int $base): ?Node
    {
        $this->s = $inner;
        $this->base = $base;
        $this->i = 0;
        $this->n = strlen($inner);

        if (trim($inner) === '') {
            return null;
        }

        try {
            $expr = $this->parseComma();
        } catch (\Throwable) {
            $trimmed = trim($inner);
            $start = $base + (strlen($inner) - strlen(ltrim($inner)));

            return new Node('ArithmeticWord', [
                'pos' => $start,
                'end' => $start + strlen($trimmed),
                'value' => $trimmed,
            ]);
        }

        return $expr;
    }

    private function parseComma(): Node
    {
        $left = $this->parseAssignment();
        while ($this->peekOp() === ',') {
            $op = $this->takeOp();
            $right = $this->parseAssignment();
            $left = new Node('ArithmeticBinary', [
                'pos' => $left->pos,
                'end' => $right->end,
                'operator' => $op,
                'left' => $left,
                'right' => $right,
            ]);
        }

        return $left;
    }

    private function parseAssignment(): Node
    {
        $left = $this->parseTernary();
        $op = $this->peekOp();
        if ($op !== null && in_array($op, self::ASSIGN_OPS, true)) {
            $this->takeOp();
            $right = $this->parseAssignment();

            return new Node('ArithmeticBinary', [
                'pos' => $left->pos,
                'end' => $right->end,
                'operator' => $op,
                'left' => $left,
                'right' => $right,
            ]);
        }

        return $left;
    }

    private function parseTernary(): Node
    {
        $cond = $this->parseBinary(0);
        if ($this->peekOp() === '?') {
            $this->takeOp();
            $consequent = $this->parseAssignment();
            if ($this->peekOp() === ':') {
                $this->takeOp();
            }
            $alternate = $this->parseAssignment();

            return new Node('ArithmeticTernary', [
                'pos' => $cond->pos,
                'end' => $alternate->end,
                'test' => $cond,
                'consequent' => $consequent,
                'alternate' => $alternate,
            ]);
        }

        return $cond;
    }

    private function parseBinary(int $level): Node
    {
        if ($level >= count(self::PRECEDENCE)) {
            return $this->parseUnary();
        }
        $left = $this->parseBinary($level + 1);
        // `**` is right-associative; every other level is left-associative.
        $rightAssoc = in_array('**', self::PRECEDENCE[$level], true);
        while (($op = $this->peekOp()) !== null && in_array($op, self::PRECEDENCE[$level], true)) {
            $this->takeOp();
            $right = $rightAssoc ? $this->parseBinary($level) : $this->parseBinary($level + 1);
            $left = new Node('ArithmeticBinary', [
                'pos' => $left->pos,
                'end' => $right->end,
                'operator' => $op,
                'left' => $left,
                'right' => $right,
            ]);
            if ($rightAssoc) {
                break;
            }
        }

        return $left;
    }

    private function parseUnary(): Node
    {
        $this->skipWs();
        $start = $this->base + $this->i;
        $op = $this->peekOp();
        if ($op !== null && in_array($op, ['+', '-', '!', '~', '++', '--'], true)) {
            $this->takeOp();
            $operand = $this->parseUnary();

            return new Node('ArithmeticUnary', [
                'pos' => $start,
                'end' => $operand->end,
                'operator' => $op,
                'operand' => $operand,
                'prefix' => true,
            ]);
        }

        $primary = $this->parsePrimary();

        $this->skipWs();
        $post = $this->peekOp();
        if ($post === '++' || $post === '--') {
            $opStart = $this->base + $this->i;
            $this->takeOp();

            return new Node('ArithmeticUnary', [
                'pos' => $primary->pos,
                'end' => $this->base + $this->i,
                'operator' => $post,
                'operand' => $primary,
                'prefix' => false,
            ]);
        }

        return $primary;
    }

    private function parsePrimary(): Node
    {
        $this->skipWs();
        $start = $this->base + $this->i;

        if ($this->i >= $this->n) {
            throw new \RuntimeException('unexpected end of arithmetic');
        }

        $c = $this->s[$this->i];

        if ($c === '(') {
            $this->i++;
            $expr = $this->parseComma();
            $this->skipWs();
            if ($this->i < $this->n && $this->s[$this->i] === ')') {
                $this->i++;
            }

            return new Node('ArithmeticGroup', [
                'pos' => $start,
                'end' => $this->base + $this->i,
                'expression' => $expr,
            ]);
        }

        // A word operand: number, identifier, or a run containing $-expansions,
        // `${...}`, `$((...))`, backticks, quotes, and `[...]` subscripts (all opaque
        // so operators inside them do not terminate the operand).
        $j = $this->i;
        while ($j < $this->n) {
            $ch = $this->s[$j];
            if (ctype_space($ch)) {
                break;
            }
            if ($ch === '$' && ($this->s[$j + 1] ?? '') === '(') {
                $j = $this->skipParenIn($j + 1);
                continue;
            }
            if ($ch === '$' && ($this->s[$j + 1] ?? '') === '{') {
                $j = $this->skipBraceIn($j + 1);
                continue;
            }
            if ($ch === '`') {
                $j++;
                while ($j < $this->n && $this->s[$j] !== '`') {
                    if ($this->s[$j] === '\\') {
                        $j++;
                    }
                    $j++;
                }
                $j++;
                continue;
            }
            if ($ch === "'") {
                $j++;
                while ($j < $this->n && $this->s[$j] !== "'") {
                    $j++;
                }
                $j++;
                continue;
            }
            if ($ch === '"') {
                $j++;
                while ($j < $this->n && $this->s[$j] !== '"') {
                    if ($this->s[$j] === '\\') {
                        $j++;
                    }
                    $j++;
                }
                $j++;
                continue;
            }
            if ($ch === '[') {
                $j = $this->skipBracketIn($j);
                continue;
            }
            if (strpos('+-*/%&|^~!<>=?:,()', $ch) !== false) {
                break;
            }
            $j++;
        }
        if ($j === $this->i) {
            throw new \RuntimeException('empty arithmetic operand');
        }
        $value = substr($this->s, $this->i, $j - $this->i);
        $runStart = $this->i;
        $this->i = $j;

        // A run that is exactly one `$(...)` (not `$((`) is a command substitution.
        if (str_starts_with($value, '$(') && ($value[2] ?? '') !== '('
            && $this->skipParenIn($runStart + 1) === $j) {
            $innerStart = $this->base + $runStart + 2;
            $inner = substr($value, 2, -1);
            $node = new Node('ArithmeticCommandExpansion', [
                'pos' => $start,
                'end' => $this->base + $j,
                'text' => $value,
                'inner' => $inner,
                'script' => null,
                'innerStart' => $innerStart,
            ]);
            $this->lexer->registerDeferred($node);

            return $node;
        }

        $parts = $this->lexer->embeddedWordParts($start, $this->base + $j);
        $props = ['pos' => $start, 'end' => $this->base + $j, 'value' => $value];
        if ($parts !== null) {
            $props['parts'] = $parts;
        }

        return new Node('ArithmeticWord', $props);
    }

    private function parseCommandExpansion(int $start): Node
    {
        // Find matching ')', skipping quoted regions and nested parens.
        $depth = 0;
        $j = $this->i + 2;
        while ($j < $this->n) {
            $ch = $this->s[$j];
            if ($ch === '\\') {
                $j += 2;
                continue;
            }
            if ($ch === "'") {
                $j++;
                while ($j < $this->n && $this->s[$j] !== "'") {
                    $j++;
                }
                $j++;
                continue;
            }
            if ($ch === '"') {
                $j++;
                while ($j < $this->n && $this->s[$j] !== '"') {
                    if ($this->s[$j] === '\\') {
                        $j++;
                    }
                    $j++;
                }
                $j++;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            $j++;
        }
        $innerStart = $this->base + $this->i + 2;
        $inner = substr($this->s, $this->i + 2, $j - ($this->i + 2));
        $end = ($j < $this->n) ? $j + 1 : $j;
        $text = substr($this->s, $this->i, $end - $this->i);
        $this->i = $end;

        $node = new Node('ArithmeticCommandExpansion', [
            'pos' => $start,
            'end' => $this->base + $end,
            'text' => $text,
            'inner' => $inner,
            'script' => null,
            'innerStart' => $innerStart,
        ]);
        $this->lexer->registerDeferred($node);

        return $node;
    }

    /** Index just past the ')' matching the '(' at $from in $this->s. */
    private function skipParenIn(int $from): int
    {
        $i = $from;
        $depth = 0;
        while ($i < $this->n) {
            $c = $this->s[$i];
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === "'") {
                $i++;
                while ($i < $this->n && $this->s[$i] !== "'") {
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === '"') {
                $i++;
                while ($i < $this->n && $this->s[$i] !== '"') {
                    if ($this->s[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            $i++;
        }

        return $i;
    }

    private function skipBraceIn(int $from): int
    {
        $i = $from;
        $depth = 0;
        while ($i < $this->n) {
            $c = $this->s[$i];
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            $i++;
        }

        return $i;
    }

    private function skipBracketIn(int $from): int
    {
        $i = $from;
        $depth = 0;
        while ($i < $this->n) {
            $c = $this->s[$i];
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            $i++;
        }

        return $i;
    }

    private function skipWs(): void
    {
        while ($this->i < $this->n && ctype_space($this->s[$this->i])) {
            $this->i++;
        }
    }

    private function peekOp(): ?string
    {
        $this->skipWs();
        if ($this->i >= $this->n) {
            return null;
        }
        $three = substr($this->s, $this->i, 3);
        if (in_array($three, ['<<=', '>>=', '**='], true)) {
            return $three;
        }
        $two = substr($this->s, $this->i, 2);
        $twoOps = ['||', '&&', '==', '!=', '<=', '>=', '<<', '>>', '**', '++', '--', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^='];
        if (in_array($two, $twoOps, true)) {
            return $two;
        }
        $one = $this->s[$this->i];
        if (strpos('+-*/%&|^~!<>=?:,', $one) !== false) {
            return $one;
        }

        return null;
    }

    private function takeOp(): string
    {
        $op = $this->peekOp();
        if ($op === null) {
            throw new \RuntimeException('expected operator');
        }
        $this->i += strlen($op);

        return $op;
    }
}
