<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * Byte-oriented Bash lexer.
 *
 * Produces a pull-based token stream for {@see Parser} and, crucially, scans
 * shell words into structured {@see Node} parts (quoting and expansions) while
 * finding correct word boundaries across nested quotes and substitutions.
 *
 * Command/process substitutions are collected as "deferred" parts carrying the
 * inner source region; {@see Parser} resolves them into nested scripts after
 * the enclosing script is parsed, so every node keeps absolute source offsets.
 *
 * @internal
 */
final class Lexer
{
    public const MAX_NESTING = 120;

    private string $source;
    private int $start;
    private int $end;
    private int $pos;

    public int $nestingDepth = 0;

    /** @var array<int, array{part: Node, depth: int}> */
    private array $deferred = [];

    /** @var ParseError[] */
    private array $errors = [];

    /** @var array<int, array{redirect: Node, delim: string, quoted: bool, strip: bool}> */
    private array $pendingHeredocs = [];

    public function __construct(string $source, int $start = 0, ?int $end = null)
    {
        $this->source = $source;
        $this->start = $start;
        $this->end = $end ?? strlen($source);
        $this->pos = $start;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function endBound(): int
    {
        return $this->end;
    }

    public function pos(): int
    {
        return $this->pos;
    }

    /** Reposition the cursor while preserving collected/deferred state. */
    public function seek(int $pos): void
    {
        $this->pos = $pos;
    }

    /** @return array<int, array{part: Node, depth: int}> */
    public function deferred(): array
    {
        return $this->deferred;
    }

    public function registerDeferred(Node $part): void
    {
        $this->deferred[] = ['part' => $part, 'depth' => $this->nestingDepth];
    }

    /** @return ParseError[] */
    public function errors(): array
    {
        return $this->errors;
    }

    private function addError(string $message, int $pos): void
    {
        $this->errors[] = new ParseError($message, $pos);
    }

    /** Build a {@see Word} for an arbitrary source region (e.g. an assignment value). */
    public function wordFromRegion(int $start, int $end): Word
    {
        return $this->makeWord($this->slice($start, $end), $start);
    }

    private function cc(int $i): int
    {
        return $i < $this->end ? ord($this->source[$i]) : -1;
    }

    private function slice(int $from, int $to): string
    {
        return substr($this->source, $from, $to - $from);
    }

    private function isBlank(int $c): bool
    {
        return $c === 32 || $c === 9;
    }

    private function isMeta(int $c): bool
    {
        // Unquoted characters that terminate a word.
        return $c === -1 || $c === 32 || $c === 9 || $c === 10
            || $c === 38 /* & */ || $c === 124 /* | */ || $c === 59 /* ; */
            || $c === 40 /* ( */ || $c === 41 /* ) */ || $c === 60 /* < */ || $c === 62 /* > */;
    }

    private function isNameStart(int $c): bool
    {
        return ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c === 95;
    }

    private function isNameChar(int $c): bool
    {
        return $this->isNameStart($c) || ($c >= 48 && $c <= 57);
    }

    private function isDigit(int $c): bool
    {
        return $c >= 48 && $c <= 57;
    }

    /** Skip inline blanks and backslash-newline line continuations. */
    private function skipBlanks(): void
    {
        while ($this->pos < $this->end) {
            $c = $this->cc($this->pos);
            if ($this->isBlank($c)) {
                $this->pos++;
            } elseif ($c === 92 && $this->cc($this->pos + 1) === 10) {
                $this->pos += 2;
            } else {
                break;
            }
        }
    }

    /**
     * Return the next token without consuming trivia beyond blanks/comments.
     *
     * @return array{type: string, op?: string, word?: Word, pos: int, end: int}
     */
    public function next(): array
    {
        $this->skipBlanks();

        // Comment: '#' that starts a token runs to end of line.
        if ($this->cc($this->pos) === 35 /* # */) {
            while ($this->pos < $this->end && $this->cc($this->pos) !== 10) {
                $this->pos++;
            }
        }

        if ($this->pos >= $this->end) {
            return ['type' => 'eof', 'pos' => $this->pos, 'end' => $this->pos];
        }

        $c = $this->cc($this->pos);

        if ($c === 10 /* \n */) {
            $nlPos = $this->pos;
            $this->pos++;
            $this->consumeHeredocs();

            return ['type' => 'newline', 'pos' => $nlPos, 'end' => $nlPos + 1];
        }

        // Operators.
        $op = $this->readOperator();
        if ($op !== null) {
            return $op;
        }

        // Otherwise a word.
        return $this->readWord();
    }

    /**
     * @return array{type: string, op: string, pos: int, end: int}|null
     */
    private function readOperator(): ?array
    {
        $p = $this->pos;
        $c = $this->cc($p);
        $c1 = $this->cc($p + 1);
        $c2 = $this->cc($p + 2);

        $mk = function (string $op, int $len) use ($p): array {
            $this->pos = $p + $len;

            return ['type' => 'op', 'op' => $op, 'pos' => $p, 'end' => $p + $len];
        };

        switch ($c) {
            case 38: // &
                if ($c1 === 38) {
                    return $mk('&&', 2);
                }
                if ($c1 === 62 && $c2 === 62) {
                    return $mk('&>>', 3);
                }
                if ($c1 === 62) {
                    return $mk('&>', 2);
                }

                return $mk('&', 1);
            case 124: // |
                if ($c1 === 124) {
                    return $mk('||', 2);
                }
                if ($c1 === 38) {
                    return $mk('|&', 2);
                }

                return $mk('|', 1);
            case 59: // ;
                if ($c1 === 59 && $c2 === 38) {
                    return $mk(';;&', 3);
                }
                if ($c1 === 59) {
                    return $mk(';;', 2);
                }
                if ($c1 === 38) {
                    return $mk(';&', 2);
                }

                return $mk(';', 1);
            case 40: // (
                return $mk('(', 1);
            case 41: // )
                return $mk(')', 1);
            case 60: // <
                if ($c1 === 40) { // <( process substitution -> word
                    return null;
                }
                if ($c1 === 60 && $c2 === 45) {
                    return $mk('<<-', 3);
                }
                if ($c1 === 60 && $c2 === 60) {
                    return $mk('<<<', 3);
                }
                if ($c1 === 60) {
                    return $mk('<<', 2);
                }
                if ($c1 === 62) {
                    return $mk('<>', 2);
                }
                if ($c1 === 38) {
                    return $mk('<&', 2);
                }

                return $mk('<', 1);
            case 62: // >
                if ($c1 === 40) { // >( process substitution -> word
                    return null;
                }
                if ($c1 === 62) {
                    return $mk('>>', 2);
                }
                if ($c1 === 38) {
                    return $mk('>&', 2);
                }
                if ($c1 === 124) {
                    return $mk('>|', 2);
                }

                return $mk('>', 1);
        }

        return null;
    }

    /**
     * Scan a word beginning at the current position.
     *
     * @return array{type: string, word: Word, pos: int, end: int}
     */
    public function readWord(): array
    {
        $startPos = $this->pos;
        [$end, $parts, $value, $text] = $this->scanWord($startPos);
        $this->pos = $end;
        $word = new Word($text, $startPos, $end, $parts, $value);

        return ['type' => 'word', 'word' => $word, 'pos' => $startPos, 'end' => $end];
    }

    /**
     * Core word scanner.
     *
     * @return array{0:int,1:?array<int,Node>,2:string,3:string} [end, parts|null, value, text]
     */
    private function scanWord(int $pos, bool $embedded = false): array
    {
        /** @var Node[] $parts */
        $parts = [];
        $litStart = $pos;
        $i = $pos;
        $hasStructure = false;

        $flushLiteral = function (int $to) use (&$parts, &$litStart): void {
            if ($to > $litStart) {
                $raw = $this->slice($litStart, $to);
                $parts[] = new Node('Literal', ['value' => $this->unescapeBare($raw), 'text' => $raw]);
            }
            $litStart = $to;
        };

        while ($i < $this->end) {
            $c = $this->cc($i);

            if ($this->isMeta($c)) {
                // '<'/'>' only end the word when not process substitution.
                if (($c === 60 || $c === 62) && $this->cc($i + 1) === 40) {
                    $flushLiteral($i);
                    [$ni, $part] = $this->scanProcessSubstitution($i);
                    $parts[] = $part;
                    $hasStructure = true;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                // In embedded mode (e.g. an array index) whitespace and shell
                // operators are literal; only the region bound terminates.
                if ($embedded && $c !== -1) {
                    $i++;
                    continue;
                }
                break;
            }

            if ($c === 92 /* \ */) {
                // Escaped char is part of the literal run (kept in text).
                $i += ($i + 1 < $this->end) ? 2 : 1;
                continue;
            }

            if ($c === 39 /* ' */) {
                $flushLiteral($i);
                [$ni, $part] = $this->scanSingleQuoted($i);
                $parts[] = $part;
                $hasStructure = true;
                $litStart = $ni;
                $i = $ni;
                continue;
            }

            if ($c === 34 /* " */) {
                $flushLiteral($i);
                [$ni, $part] = $this->scanDoubleQuoted($i, false);
                $parts[] = $part;
                $hasStructure = true;
                $litStart = $ni;
                $i = $ni;
                continue;
            }

            if ($c === 36 /* $ */) {
                $nx = $this->cc($i + 1);
                if ($nx === 39) { // $'
                    $flushLiteral($i);
                    [$ni, $part] = $this->scanAnsiC($i);
                    $parts[] = $part;
                    $hasStructure = true;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($nx === 34) { // $"
                    $flushLiteral($i);
                    [$ni, $part] = $this->scanDoubleQuoted($i, true);
                    $parts[] = $part;
                    $hasStructure = true;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($nx === 123) { // ${
                    $flushLiteral($i);
                    [$ni, $part] = $this->scanDollarBrace($i);
                    $parts[] = $part;
                    $hasStructure = true;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($nx === 40) { // $(
                    $flushLiteral($i);
                    [$ni, $part] = $this->scanDollarParen($i);
                    $parts[] = $part;
                    $hasStructure = true;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($this->isSimpleExpansionStart($nx)) {
                    $flushLiteral($i);
                    [$ni, $part] = $this->scanSimpleExpansion($i);
                    $parts[] = $part;
                    $hasStructure = true;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                // Lone '$' — literal.
                $i++;
                continue;
            }

            if ($c === 96 /* ` */) {
                $flushLiteral($i);
                [$ni, $part] = $this->scanBacktick($i);
                $parts[] = $part;
                $hasStructure = true;
                $litStart = $ni;
                $i = $ni;
                continue;
            }

            if ($c === 123 /* { */) {
                $be = $this->tryScanBraceExpansion($i);
                if ($be !== null) {
                    $flushLiteral($i);
                    [$ni, $part] = $be;
                    $parts[] = $part;
                    $hasStructure = true;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                $i++;
                continue;
            }

            // Extended glob: ?( *( +( @( !(
            if (($c === 63 || $c === 42 || $c === 43 || $c === 64 || $c === 33) && $this->cc($i + 1) === 40) {
                $flushLiteral($i);
                [$ni, $part] = $this->scanExtGlob($i);
                $parts[] = $part;
                $hasStructure = true;
                $litStart = $ni;
                $i = $ni;
                continue;
            }

            $i++;
        }

        $flushLiteral($i);
        $text = $this->slice($pos, $i);

        if (!$hasStructure) {
            return [$i, null, $this->unescapeBare($text), $text];
        }

        return [$i, $parts, $this->computeValue($parts), $text];
    }

    private function isSimpleExpansionStart(int $c): bool
    {
        // Names, positional/special parameters.
        return $this->isNameStart($c) || $this->isDigit($c)
            || $c === 63 /* ? */ || $c === 36 /* $ */ || $c === 33 /* ! */
            || $c === 35 /* # */ || $c === 42 /* * */ || $c === 64 /* @ */ || $c === 45 /* - */;
    }

    /** @return array{0:int,1:Node} */
    private function scanSimpleExpansion(int $i): array
    {
        $start = $i;
        $i++; // '$'
        $c = $this->cc($i);
        if ($this->isNameStart($c)) {
            $i++;
            while ($this->isNameChar($this->cc($i))) {
                $i++;
            }
        } elseif ($this->isDigit($c)) {
            $i++; // single digit positional parameter
        } else {
            $i++; // special single-char parameter
        }
        $text = $this->slice($start, $i);

        return [$i, new Node('SimpleExpansion', ['text' => $text])];
    }

    /** @return array{0:int,1:Node} */
    private function scanSingleQuoted(int $i): array
    {
        $start = $i;
        $i++; // opening '
        $vstart = $i;
        while ($i < $this->end && $this->cc($i) !== 39) {
            $i++;
        }
        $value = $this->slice($vstart, $i);
        if ($i < $this->end) {
            $i++; // closing '
        } else {
            $this->addError('unterminated single quote', $start);
        }
        $text = $this->slice($start, $i);

        return [$i, new Node('SingleQuoted', ['value' => $value, 'text' => $text])];
    }

    /**
     * @return array{0:int,1:Node}
     */
    private function scanDoubleQuoted(int $i, bool $locale): array
    {
        $start = $i;
        $i += $locale ? 2 : 1; // opening " (or $" for locale strings)
        /** @var Node[] $parts */
        $parts = [];
        $litStart = $i;

        $flush = function (int $to) use (&$parts, &$litStart): void {
            if ($to > $litStart) {
                $raw = $this->slice($litStart, $to);
                $parts[] = new Node('Literal', ['value' => $this->unescapeDouble($raw), 'text' => $raw]);
            }
            $litStart = $to;
        };

        while ($i < $this->end && $this->cc($i) !== 34) {
            $c = $this->cc($i);
            if ($c === 92) { // backslash keeps escaping the next char
                $i += ($i + 1 < $this->end) ? 2 : 1;
                continue;
            }
            if ($c === 36) { // $
                $nx = $this->cc($i + 1);
                if ($nx === 123) {
                    $flush($i);
                    [$ni, $part] = $this->scanDollarBrace($i);
                    $parts[] = $part;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($nx === 40) {
                    $flush($i);
                    [$ni, $part] = $this->scanDollarParen($i);
                    $parts[] = $part;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($this->isSimpleExpansionStart($nx)) {
                    $flush($i);
                    [$ni, $part] = $this->scanSimpleExpansion($i);
                    $parts[] = $part;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                $i++;
                continue;
            }
            if ($c === 96) { // backtick
                $flush($i);
                [$ni, $part] = $this->scanBacktick($i);
                $parts[] = $part;
                $litStart = $ni;
                $i = $ni;
                continue;
            }
            $i++;
        }
        $flush($i);
        if ($i < $this->end) {
            $i++; // closing "
        } else {
            $this->addError('unterminated double quote', $start);
        }
        $text = $this->slice($start, $i);

        return [$i, new Node($locale ? 'LocaleString' : 'DoubleQuoted', ['text' => $text, 'parts' => $parts])];
    }

    /** @return array{0:int,1:Node} */
    private function scanAnsiC(int $i): array
    {
        $start = $i;
        $i += 2; // $'
        $vstart = $i;
        while ($i < $this->end && $this->cc($i) !== 39) {
            if ($this->cc($i) === 92 && $i + 1 < $this->end) {
                $i += 2;
            } else {
                $i++;
            }
        }
        $raw = $this->slice($vstart, $i);
        if ($i < $this->end) {
            $i++; // closing '
        } else {
            $this->addError('unterminated ANSI-C quote', $start + 1);
        }
        $text = $this->slice($start, $i);

        return [$i, new Node('AnsiCQuoted', ['text' => $text, 'value' => $this->decodeAnsiC($raw)])];
    }

    /**
     * Consume a balanced region up to a closing char, honoring quotes/nesting.
     *
     * @return array{0:int,1:bool} [index just past the closer (or end bound), whether the closer was found]
     */
    private function matchBalanced(int $i, int $open, int $close): array
    {
        $depth = 0;
        while ($i < $this->end) {
            $c = $this->cc($i);
            if ($c === 92) {
                $i += ($i + 1 < $this->end) ? 2 : 1;
                continue;
            }
            if ($c === 39) { // single quote
                $i++;
                while ($i < $this->end && $this->cc($i) !== 39) {
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === 34) { // double quote
                $i++;
                while ($i < $this->end && $this->cc($i) !== 34) {
                    if ($this->cc($i) === 92) {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === $open) {
                $depth++;
                $i++;
                continue;
            }
            if ($c === $close) {
                if ($depth === 0) {
                    return [$i + 1, true];
                }
                $depth--;
                $i++;
                continue;
            }
            $i++;
        }

        return [$i, false];
    }

    /**
     * Decide `$(( ))` arithmetic vs `$( (...) )` command substitution and scan it.
     *
     * @return array{0:int,1:Node}
     */
    private function scanDollarParen(int $i): array
    {
        if ($this->cc($i + 2) === 40 && $this->innerParenClosesArith($i + 2)) {
            return $this->scanArithmeticExpansion($i);
        }

        return $this->scanCommandExpansion($i);
    }

    /**
     * Decide `${ }`/`${| }` command funsub vs `${...}` parameter expansion and scan it.
     *
     * @return array{0:int,1:Node}
     */
    private function scanDollarBrace(int $i): array
    {
        $c2 = $this->cc($i + 2);
        if ($c2 === 32 || $c2 === 9 || $c2 === 10 || $c2 === 124 /* | */) {
            return $this->scanBraceFunsub($i);
        }

        return $this->scanParameterExpansion($i);
    }

    /** True when the `(` at $from closes immediately before another `)` (so `$((...))` is arithmetic). */
    private function innerParenClosesArith(int $from): bool
    {
        $m = $this->matchOneParen($from);

        return $m + 1 < $this->end && $this->cc($m + 1) === 41;
    }

    private function matchOneParen(int $from): int
    {
        $i = $from;
        $depth = 0;
        while ($i < $this->end) {
            $c = $this->cc($i);
            if ($c === 92) {
                $i += 2;
                continue;
            }
            if ($c === 39) {
                $i++;
                while ($i < $this->end && $this->cc($i) !== 39) {
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === 34) {
                $i++;
                while ($i < $this->end && $this->cc($i) !== 34) {
                    if ($this->cc($i) === 92) {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === 40) {
                $depth++;
            } elseif ($c === 41) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
            $i++;
        }

        return $i;
    }

    /** Bash 5.3 command funsub: `${ cmd; }` or `${| cmd; }`. @return array{0:int,1:Node} */
    private function scanBraceFunsub(int $i): array
    {
        $start = $i;
        $bodyStart = $this->cc($i + 2) === 124 ? $i + 3 : $i + 2;
        [$close, $found] = $this->matchBalanced($i + 2, 123, 125);
        if (!$found) {
            $this->addError('unterminated command substitution', $start);
        }
        $innerEnd = $found ? $close - 1 : $close;
        $inner = $this->slice($bodyStart, $innerEnd);
        $text = $this->slice($start, $close);
        $part = new Node('CommandExpansion', [
            'text' => $text,
            'script' => null,
            'inner' => $inner,
            'innerStart' => $bodyStart,
        ]);
        $this->deferred[] = ['part' => $part, 'depth' => $this->nestingDepth];

        return [$close, $part];
    }

    /** @return array{0:int,1:Node} */
    private function scanCommandExpansion(int $i): array
    {
        $start = $i;
        $innerStart = $i + 2; // past "$("
        [$close, $found] = $this->matchBalanced($innerStart, 40, 41);
        if (!$found) {
            $this->addError('unterminated command substitution', $start);
        }
        $innerEnd = $found ? $close - 1 : $close;
        $inner = $this->slice($innerStart, $innerEnd);
        $text = $this->slice($start, $close);
        $part = new Node('CommandExpansion', [
            'text' => $text,
            'script' => null,
            'inner' => $inner,
            'innerStart' => $innerStart,
        ]);
        $this->deferred[] = ['part' => $part, 'depth' => $this->nestingDepth];

        return [$close, $part];
    }

    /** @return array{0:int,1:Node} */
    private function scanBacktick(int $i): array
    {
        $start = $i;
        $i++; // opening `
        $innerStart = $i;
        while ($i < $this->end && $this->cc($i) !== 96) {
            if ($this->cc($i) === 92 && $i + 1 < $this->end) {
                $i += 2;
            } else {
                $i++;
            }
        }
        $innerEnd = $i;
        $inner = $this->slice($innerStart, $innerEnd);
        if ($i < $this->end) {
            $i++; // closing `
        }
        $text = $this->slice($start, $i);
        // Decode common backtick escapes.
        $decoded = preg_replace('/\\\\([`$\\\\])/', '$1', $inner);
        $part = new Node('CommandExpansion', [
            'text' => $text,
            'script' => null,
            'inner' => $decoded,
            'innerStart' => ($decoded === $inner) ? $innerStart : null,
        ]);
        $this->deferred[] = ['part' => $part, 'depth' => $this->nestingDepth];

        return [$i, $part];
    }

    /** @return array{0:int,1:Node} */
    private function scanProcessSubstitution(int $i): array
    {
        $start = $i;
        $operator = $this->cc($i) === 60 ? '<' : '>';
        $innerStart = $i + 2; // past "<(" / ">("
        [$close, $found] = $this->matchBalanced($innerStart, 40, 41);
        if (!$found) {
            $this->addError('unterminated process substitution', $start);
        }
        $innerEnd = $found ? $close - 1 : $close;
        $inner = $this->slice($innerStart, $innerEnd);
        $text = $this->slice($start, $close);
        $part = new Node('ProcessSubstitution', [
            'operator' => $operator,
            'text' => $text,
            'script' => null,
            'inner' => $inner,
            'innerStart' => $innerStart,
        ]);
        $this->deferred[] = ['part' => $part, 'depth' => $this->nestingDepth];

        return [$close, $part];
    }

    /**
     * Find the index of the first ')' of the terminating "))" at paren depth 0,
     * starting from $from. Skips quoted regions and nested parens (including
     * command substitutions), so quoted ')' does not desync the scan.
     */
    public function findDoubleParenClose(int $from): int
    {
        $i = $from;
        $depth = 0;
        while ($i < $this->end) {
            $c = $this->cc($i);
            if ($c === 92) {
                $i += ($i + 1 < $this->end) ? 2 : 1;
                continue;
            }
            if ($c === 39) {
                $i++;
                while ($i < $this->end && $this->cc($i) !== 39) {
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === 34) {
                $i++;
                while ($i < $this->end && $this->cc($i) !== 34) {
                    if ($this->cc($i) === 92) {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === 40) {
                $depth++;
                $i++;
                continue;
            }
            if ($c === 41) {
                if ($depth === 0 && $this->cc($i + 1) === 41) {
                    return $i;
                }
                $depth--;
                $i++;
                continue;
            }
            $i++;
        }

        return $i;
    }

    /** @return array{0:int,1:Node} */
    private function scanArithmeticExpansion(int $i): array
    {
        $start = $i;
        $innerStart = $i + 3; // past "$(("
        $j = $this->findDoubleParenClose($innerStart);
        $innerEnd = $j;
        $inner = $this->slice($innerStart, $innerEnd);
        $close = ($j < $this->end) ? $j + 2 : $j;
        $text = $this->slice($start, $close);
        $expr = (new ArithmeticParser($this))->parse($inner, $innerStart);

        return [$close, new Node('ArithmeticExpansion', ['text' => $text, 'expression' => $expr])];
    }

    /** @return array{0:int,1:Node} */
    private function scanParameterExpansion(int $i): array
    {
        $start = $i;
        $innerStart = $i + 2; // past "${"
        [$close, $found] = $this->matchBalanced($innerStart, 123, 125);
        if (!$found) {
            $this->addError('unterminated parameter expansion', $start);
        }
        $innerEnd = $found ? $close - 1 : $close;
        $inner = $this->slice($innerStart, $innerEnd);
        $text = $this->slice($start, $close);

        $props = [
            'text' => $text,
            'parameter' => '',
            'index' => null,
            'indirect' => null,
            'length' => null,
            'operator' => null,
            'operand' => null,
            'slice' => null,
            'replace' => null,
        ];

        $this->fillParameterExpansion($props, $inner, $innerStart);

        return [$close, new Node('ParameterExpansion', $props)];
    }

    /**
     * @param array<string,mixed> $props
     */
    private function fillParameterExpansion(array &$props, string $inner, int $innerStart): void
    {
        $n = strlen($inner);
        $k = 0;

        if ($n === 0) {
            return;
        }

        // ${#name} length, ${!name} indirect.
        if ($inner[0] === '#' && $n > 1) {
            $props['length'] = true;
            $k = 1;
        } elseif ($inner[0] === '!' && $n > 1) {
            $props['indirect'] = true;
            $k = 1;
        }

        // Parameter name (or special param).
        $nameStart = $k;
        if ($k < $n && ($this->isNameStart(ord($inner[$k])))) {
            while ($k < $n && $this->isNameChar(ord($inner[$k]))) {
                $k++;
            }
        } elseif ($k < $n && ctype_digit($inner[$k])) {
            while ($k < $n && ctype_digit($inner[$k])) {
                $k++;
            }
        } elseif ($k < $n) {
            $k++; // special single-char param (?, @, *, #, $, !, -, 0)
        }
        $props['parameter'] = substr($inner, $nameStart, $k - $nameStart);

        // Array index ${name[expr]}.
        if ($k < $n && $inner[$k] === '[') {
            $depth = 0;
            $idxStart = $k + 1;
            $j = $idxStart;
            while ($j < $n) {
                if ($inner[$j] === '[') {
                    $depth++;
                } elseif ($inner[$j] === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                }
                $j++;
            }
            $props['index'] = substr($inner, $idxStart, $j - $idxStart);
            $idxParts = $this->partsOf($props['index'], $innerStart + $idxStart, true);
            if ($idxParts !== null) {
                $props['indexParts'] = $idxParts;
            }
            $k = ($j < $n) ? $j + 1 : $j;
        }

        if ($k >= $n) {
            return;
        }

        $rest = substr($inner, $k);
        $restStart = $innerStart + $k;

        // Replace: /pat/rep, //pat/rep, /#pat/rep, /%pat/rep
        if ($rest[0] === '/') {
            $p = 1;
            if (isset($rest[$p]) && ($rest[$p] === '/' || $rest[$p] === '#' || $rest[$p] === '%')) {
                $p++;
            }
            $patStart = $p;
            while ($p < strlen($rest) && $rest[$p] !== '/') {
                if ($rest[$p] === '\\') {
                    $p++;
                }
                $p++;
            }
            $pattern = substr($rest, $patStart, $p - $patStart);
            $replacement = '';
            $repStart = $p;
            if ($p < strlen($rest) && $rest[$p] === '/') {
                $repStart = $p + 1;
                $replacement = substr($rest, $repStart);
            }
            $props['operator'] = substr($rest, 0, $patStart);
            $props['replace'] = [
                'pattern' => $this->makeWord($pattern, $restStart + $patStart),
                'replacement' => $this->makeWord($replacement, $restStart + $repStart),
            ];

            return;
        }

        // Slice: :offset[:length]  (but not :- := :? :+)
        if ($rest[0] === ':' && (!isset($rest[1]) || strpos('-=?+', $rest[1]) === false)) {
            $body = substr($rest, 1);
            $colon = $this->topLevelColon($body);
            if ($colon === -1) {
                $offset = $body;
                $length = null;
                $lenWord = null;
            } else {
                $offset = substr($body, 0, $colon);
                $length = substr($body, $colon + 1);
                $lenWord = $this->makeWord($length, $restStart + 1 + $colon + 1);
            }
            $props['operator'] = ':';
            $props['slice'] = [
                'offset' => $this->makeWord($offset, $restStart + 1),
                'length' => $lenWord,
            ];

            return;
        }

        // General operator: :- := :? :+ - = ? + # ## % %% ^ ^^ , ,, @X
        $ops = [':-', ':=', ':?', ':+', '##', '%%', '^^', ',,', '-', '=', '?', '+', '#', '%', '^', ','];
        $matched = null;
        foreach ($ops as $cand) {
            if (strncmp($rest, $cand, strlen($cand)) === 0) {
                $matched = $cand;
                break;
            }
        }
        if ($rest[0] === '@') {
            $matched = '@';
        }
        if ($matched !== null) {
            $props['operator'] = $matched;
            $operandStr = substr($rest, strlen($matched));
            $props['operand'] = $this->makeWord($operandStr, $restStart + strlen($matched));
        }
    }

    private function topLevelColon(string $s): int
    {
        $depth = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === ':' && $depth === 0) {
                return $i;
            }
        }

        return -1;
    }

    /** @return array{0:int,1:Node}|null */
    private function tryScanBraceExpansion(int $i): ?array
    {
        // A brace expansion contains a top-level comma or `..` range and a matching
        // `}` with no unquoted whitespace at the top level. Quotes and expansions
        // are opaque (their contents are skipped).
        $depth = 0;
        $j = $i + 1;
        $hasComma = false;
        $hasRange = false;
        while ($j < $this->end) {
            $c = $this->cc($j);
            if ($c === 92) {
                $j += ($j + 1 < $this->end) ? 2 : 1;
                continue;
            }
            if ($c === 39) { // '...'
                $j++;
                while ($j < $this->end && $this->cc($j) !== 39) {
                    $j++;
                }
                $j++;
                continue;
            }
            if ($c === 34) { // "..."
                $j++;
                while ($j < $this->end && $this->cc($j) !== 34) {
                    if ($this->cc($j) === 92) {
                        $j++;
                    }
                    $j++;
                }
                $j++;
                continue;
            }
            if ($c === 96) { // `...`
                $j++;
                while ($j < $this->end && $this->cc($j) !== 96) {
                    if ($this->cc($j) === 92) {
                        $j++;
                    }
                    $j++;
                }
                $j++;
                continue;
            }
            if ($c === 36 && $this->cc($j + 1) === 40) { // $(
                [$j] = $this->matchBalanced($j + 2, 40, 41);
                continue;
            }
            if ($c === 36 && $this->cc($j + 1) === 123) { // ${
                [$j] = $this->matchBalanced($j + 2, 123, 125);
                continue;
            }
            if (($c === 60 || $c === 62) && $this->cc($j + 1) === 40) { // <( >(
                [$j] = $this->matchBalanced($j + 2, 40, 41);
                continue;
            }
            if ($c === 123) {
                $depth++;
                $j++;
                continue;
            }
            if ($c === 125) {
                if ($depth === 0) {
                    if (!$hasComma && !$hasRange) {
                        return null;
                    }
                    $end = $j + 1;
                    $text = $this->slice($i, $end);
                    $parts = $this->partsOf($this->slice($i + 1, $j), $i + 1);

                    return [$end, new Node('BraceExpansion', ['text' => $text, 'parts' => $parts])];
                }
                $depth--;
                $j++;
                continue;
            }
            if ($c === 44 && $depth === 0) {
                $hasComma = true;
                $j++;
                continue;
            }
            if ($c === 46 && $this->cc($j + 1) === 46 && $depth === 0) {
                $hasRange = true;
                $j += 2;
                continue;
            }
            if ($this->isBlank($c) || $c === 10 || $this->isMeta($c)) {
                return null;
            }
            $j++;
        }

        return null;
    }

    /** @return array{0:int,1:Node} */
    private function scanExtGlob(int $i): array
    {
        $operator = $this->source[$i];
        $start = $i;
        [$close, $found] = $this->matchBalanced($i + 2, 40, 41);
        if (!$found) {
            $this->addError('unterminated extended glob', $start);
        }
        $patStart = $i + 2;
        $patEnd = $found ? $close - 1 : $close;
        $pattern = $this->slice($patStart, $patEnd);
        $text = $this->slice($start, $close);
        $parts = $this->partsOf($pattern, $patStart, true);

        return [$close, new Node('ExtendedGlob', [
            'text' => $text,
            'operator' => $operator,
            'pattern' => $pattern,
            'parts' => $parts,
        ])];
    }

    /**
     * Scan a standalone region as word parts (used for indexes, patterns, ...).
     *
     * @return Node[]|null
     */
    private function partsOf(string $region, int $absStart, bool $embedded = false): ?array
    {
        if ($region === '') {
            return null;
        }
        $sub = new self($this->source, $absStart, $absStart + strlen($region));
        $sub->nestingDepth = $this->nestingDepth;
        [, $parts] = $sub->scanWord($absStart, $embedded);
        foreach ($sub->deferred as $d) {
            $this->deferred[] = $d;
        }
        foreach ($sub->errors as $e) {
            $this->errors[] = $e;
        }

        return $parts;
    }

    private function makeWord(string $text, int $absStart): Word
    {
        $sub = new self($this->source, $absStart, $absStart + strlen($text));
        $sub->nestingDepth = $this->nestingDepth;
        [$end, $parts, $value] = $sub->scanWord($absStart);
        foreach ($sub->deferred as $d) {
            $this->deferred[] = $d;
        }
        foreach ($sub->errors as $e) {
            $this->errors[] = $e;
        }

        return new Word($text, $absStart, $absStart + strlen($text), $parts, $value);
    }

    /**
     * @param Node[] $parts
     */
    private function computeValue(array $parts): string
    {
        $s = '';
        foreach ($parts as $p) {
            switch ($p->type) {
                case 'Literal':
                case 'SingleQuoted':
                case 'AnsiCQuoted':
                    $s .= $p->value;
                    break;
                case 'DoubleQuoted':
                case 'LocaleString':
                    $s .= $this->dequoteValue($p->parts ?? []);
                    break;
                case 'CommandExpansion':
                    $s .= $this->commandExpansionValue($p->text);
                    break;
                default:
                    $s .= $p->text;
                    break;
            }
        }

        return $s;
    }

    /**
     * @param Node[] $parts
     */
    private function dequoteValue(array $parts): string
    {
        $s = '';
        foreach ($parts as $c) {
            $s .= $c->type === 'Literal' ? $c->value : $c->text;
        }

        return $s;
    }

    private function commandExpansionValue(string $text): string
    {
        return $text;
    }

    private function unescapeBare(string $text): string
    {
        if (strpos($text, '\\') === false) {
            return $text;
        }
        $out = '';
        $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            if ($text[$i] === '\\') {
                if ($i + 1 >= $n) {
                    $out .= '\\';
                    break;
                }
                $i++;
                if ($text[$i] !== "\n") {
                    $out .= $text[$i];
                }
                continue;
            }
            $out .= $text[$i];
        }

        return $out;
    }

    private function unescapeDouble(string $text): string
    {
        if (strpos($text, '\\') === false) {
            return $text;
        }
        $out = '';
        $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            if ($text[$i] === '\\' && $i + 1 < $n) {
                $nx = $text[$i + 1];
                if (in_array($nx, ['$', '`', '"', '\\', "\n"], true)) {
                    $i++;
                    if ($nx !== "\n") {
                        $out .= $nx;
                    }
                    continue;
                }
            }
            $out .= $text[$i];
        }

        return $out;
    }

    private function decodeAnsiC(string $s): string
    {
        $out = '';
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if ($c !== '\\') {
                $out .= $c;
                $i++;
                continue;
            }
            if ($i + 1 >= $n) {
                $out .= '\\'; // trailing backslash is literal
                break;
            }
            $i++;
            $e = $s[$i];
            switch ($e) {
                case 'n': $out .= "\n"; $i++; break;
                case 't': $out .= "\t"; $i++; break;
                case 'r': $out .= "\r"; $i++; break;
                case 'a': $out .= "\x07"; $i++; break;
                case 'b': $out .= "\x08"; $i++; break;
                case 'f': $out .= "\x0C"; $i++; break;
                case 'v': $out .= "\x0B"; $i++; break;
                case 'e':
                case 'E': $out .= "\x1B"; $i++; break;
                case '\\': $out .= '\\'; $i++; break;
                case "'": $out .= "'"; $i++; break;
                case '"': $out .= '"'; $i++; break;
                case '?': $out .= '?'; $i++; break;
                case 'x':
                    $i++;
                    $hex = '';
                    while ($i < $n && strlen($hex) < 2 && ctype_xdigit($s[$i])) {
                        $hex .= $s[$i];
                        $i++;
                    }
                    $out .= $hex === '' ? '\\x' : chr(hexdec($hex) & 0xFF);
                    break;
                case 'u':
                case 'U':
                    $max = $e === 'u' ? 4 : 8;
                    $i++;
                    $hex = '';
                    while ($i < $n && strlen($hex) < $max && ctype_xdigit($s[$i])) {
                        $hex .= $s[$i];
                        $i++;
                    }
                    $out .= $hex === '' ? '\\' . $e : $this->utf8((int) hexdec($hex));
                    break;
                case 'c':
                    $i++;
                    if ($i >= $n) {
                        $out .= '\\c';
                        break;
                    }
                    $x = $s[$i];
                    $i++;
                    $out .= $x === '?' ? "\x7F" : chr(ord($x) & 0x1F);
                    break;
                default:
                    if ($e >= '0' && $e <= '7') {
                        $oct = $e;
                        $i++;
                        while ($i < $n && strlen($oct) < 3 && $s[$i] >= '0' && $s[$i] <= '7') {
                            $oct .= $s[$i];
                            $i++;
                        }
                        $out .= chr(octdec($oct) & 0xFF);
                    } else {
                        $out .= '\\' . $e;
                        $i++;
                    }
                    break;
            }
        }

        return $out;
    }

    private function utf8(int $cp): string
    {
        if (function_exists('mb_chr')) {
            $ch = mb_chr($cp, 'UTF-8');
            if ($ch !== false) {
                return $ch;
            }
        }

        return $cp < 128 ? chr($cp) : '';
    }

    // --- Heredoc handling -------------------------------------------------

    public function registerHeredoc(Node $redirect, string $delim, bool $quoted, bool $strip): void
    {
        $this->pendingHeredocs[] = [
            'redirect' => $redirect,
            'delim' => $delim,
            'quoted' => $quoted,
            'strip' => $strip,
        ];
    }

    private function consumeHeredocs(): void
    {
        if ($this->pendingHeredocs === []) {
            return;
        }
        $pending = $this->pendingHeredocs;
        $this->pendingHeredocs = [];
        foreach ($pending as $h) {
            $bodyStart = $this->pos;
            $i = $this->pos;
            $delim = $h['delim'];
            $strip = $h['strip'];
            $closed = false;
            $bodyEnd = $i;
            while ($i < $this->end) {
                $lineStart = $i;
                while ($i < $this->end && $this->cc($i) !== 10) {
                    $i++;
                }
                $line = $this->slice($lineStart, $i);
                $check = $strip ? ltrim($line, "\t") : $line;
                if ($check === $delim) {
                    $bodyEnd = $lineStart;
                    $i = ($i < $this->end) ? $i + 1 : $i; // consume newline after delimiter
                    $closed = true;
                    break;
                }
                if ($i < $this->end) {
                    $i++; // consume newline
                }
                $bodyEnd = $i;
            }
            $bodyText = $this->slice($bodyStart, $bodyEnd);
            $parts = $h['quoted'] ? null : $this->heredocParts($bodyText, $bodyStart);
            $redirect = $h['redirect'];
            $redirect->content = $bodyText;
            $redirect->heredocQuoted = $h['quoted'] ? true : null;
            // A body Word is present only for an unquoted heredoc that actually
            // contains expansions; otherwise `body` is absent.
            $redirect->body = $parts === null
                ? null
                : new Word($bodyText, $bodyStart, $bodyEnd, $parts, $this->computeValue($parts));
            $this->pos = $i;
            if (!$closed) {
                $this->pos = $this->end;
            }
        }
    }

    /**
     * Compute expansion parts for an unquoted heredoc body ($ and backticks are
     * active; quotes and newlines are literal).
     *
     * @return Node[]|null
     */
    private function heredocParts(string $body, int $absStart): ?array
    {
        if (strpos($body, '$') === false && strpos($body, '`') === false) {
            return null;
        }
        /** @var Node[] $parts */
        $parts = [];
        $hasExpansion = false;
        $sub = new self($this->source, $absStart, $absStart + strlen($body));
        $sub->nestingDepth = $this->nestingDepth;
        $i = $absStart;
        $litStart = $i;
        $end = $absStart + strlen($body);
        $flush = function (int $to) use (&$parts, &$litStart, $sub): void {
            if ($to > $litStart) {
                $raw = $sub->slice($litStart, $to);
                $parts[] = new Node('Literal', ['value' => $raw, 'text' => $raw]);
            }
            $litStart = $to;
        };
        while ($i < $end) {
            $c = $sub->cc($i);
            if ($c === 92) { // backslash escapes the next char (e.g. \$ is literal)
                $i += ($i + 1 < $end) ? 2 : 1;
                continue;
            }
            if ($c === 36) {
                $nx = $sub->cc($i + 1);
                if ($nx === 123) {
                    $flush($i);
                    [$ni, $part] = $sub->scanDollarBrace($i);
                    $parts[] = $part;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($nx === 40) {
                    $flush($i);
                    [$ni, $part] = $sub->scanDollarParen($i);
                    $parts[] = $part;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
                if ($sub->isSimpleExpansionStart($nx)) {
                    $flush($i);
                    [$ni, $part] = $sub->scanSimpleExpansion($i);
                    $parts[] = $part;
                    $litStart = $ni;
                    $i = $ni;
                    continue;
                }
            }
            if ($c === 96) {
                $flush($i);
                [$ni, $part] = $sub->scanBacktick($i);
                $parts[] = $part;
                $litStart = $ni;
                $i = $ni;
                continue;
            }
            $i++;
        }
        $flush($end);
        foreach ($sub->deferred as $d) {
            $this->deferred[] = $d;
        }
        foreach ($sub->errors as $e) {
            $this->errors[] = $e;
        }

        foreach ($parts as $p) {
            if ($p->type !== 'Literal') {
                $hasExpansion = true;
                break;
            }
        }

        return $hasExpansion ? $parts : null;
    }
}
