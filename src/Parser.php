<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * Recursive-descent Bash parser.
 *
 * Consumes tokens from {@see Lexer} and produces the unbash AST rooted at a
 * `Script` {@see Node}. Parsing is tolerant: malformed input never throws; it
 * records {@see ParseError}s and returns a best-effort partial tree.
 *
 * @internal
 */
final class Parser
{
    private Lexer $lexer;
    private int $start;
    private int $end;
    private int $depth;

    /** @var array<int, array{type: string, op?: string, word?: Word, pos: int, end: int}> */
    private array $buf = [];

    /** @var ParseError[] */
    private array $errors = [];

    private int $lastEnd;

    private const COMPOUND_KEYWORDS = [
        'if', 'for', 'while', 'until', 'case', 'select', 'function', 'coproc',
    ];

    private const TEST_UNARY = [
        '-a', '-b', '-c', '-d', '-e', '-f', '-g', '-h', '-k', '-p', '-r', '-s',
        '-t', '-u', '-w', '-x', '-O', '-G', '-L', '-N', '-S', '-o', '-v', '-n',
        '-z', '-R',
    ];

    private const TEST_BINARY = [
        '==', '=', '!=', '=~', '-eq', '-ne', '-lt', '-le', '-gt', '-ge',
        '-nt', '-ot', '-ef',
    ];

    public function __construct(string $source, int $start = 0, ?int $end = null, int $depth = 0)
    {
        $this->start = $start;
        $this->end = $end ?? strlen($source);
        $this->depth = $depth;
        $this->lexer = new Lexer($source, $start, $this->end);
        $this->lexer->nestingDepth = $depth;
        $this->lastEnd = $start;
    }

    public function parseScript(): Node
    {
        $shebang = $this->readShebang();
        $commands = $this->parseStatements([], []);
        $this->resolveDeferred();

        $errors = array_merge($this->lexer->errors(), $this->errors);
        usort($errors, static fn (ParseError $a, ParseError $b) => $a->pos <=> $b->pos);

        $script = new Node('Script', [
            'pos' => $this->start,
            'end' => $this->end,
            'shebang' => $shebang,
            'commands' => $commands,
        ]);
        // Match the reference: `errors` is absent/null when there are none.
        $script->errors = $errors === [] ? null : $errors;

        return $script;
    }

    private function readShebang(): ?string
    {
        $src = $this->lexer->source();
        if ($this->start + 1 < $this->end && $src[$this->start] === '#' && $src[$this->start + 1] === '!') {
            $i = $this->start;
            while ($i < $this->end && $src[$i] !== "\n") {
                $i++;
            }

            return substr($src, $this->start, $i - $this->start);
        }

        return null;
    }

    // --- Token stream -----------------------------------------------------

    /**
     * @return array{type: string, op?: string, word?: Word, pos: int, end: int}
     */
    private function peek(int $k = 0): array
    {
        while (count($this->buf) <= $k) {
            $this->buf[] = $this->lexer->next();
        }

        return $this->buf[$k];
    }

    /**
     * @return array{type: string, op?: string, word?: Word, pos: int, end: int}
     */
    private function advance(): array
    {
        $t = $this->peek(0);
        array_shift($this->buf);
        $this->lastEnd = $t['end'];

        return $t;
    }

    private function isWord(array $t, ?string $text = null): bool
    {
        if ($t['type'] !== 'word') {
            return false;
        }

        return $text === null || $this->kwText($t) === $text;
    }

    /** Reserved-word candidate text: line continuations are removed, other escapes/quotes are not. */
    private function kwText(array $t): string
    {
        if ($t['type'] !== 'word') {
            return '';
        }

        return str_replace("\\\n", '', $t['word']->text);
    }

    private function isOp(array $t, string ...$ops): bool
    {
        return $t['type'] === 'op' && in_array($t['op'], $ops, true);
    }

    private function error(string $message, int $pos): void
    {
        $this->errors[] = new ParseError($message, $pos);
    }

    // --- Statement lists --------------------------------------------------

    private function skipTerminators(): void
    {
        while (true) {
            $t = $this->peek();
            if ($t['type'] === 'newline' || $this->isOp($t, ';')) {
                $this->advance();
                continue;
            }
            break;
        }
    }

    /**
     * @param string[] $stopWords
     * @param string[] $stopOps
     */
    private function atStop(array $stopWords, array $stopOps): bool
    {
        $t = $this->peek();
        if ($t['type'] === 'eof') {
            return true;
        }
        if ($t['type'] === 'word' && in_array($t['word']->text, $stopWords, true)) {
            return true;
        }
        if ($t['type'] === 'op' && in_array($t['op'], $stopOps, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param string[] $stopWords
     * @param string[] $stopOps
     * @return Node[]
     */
    private function parseStatements(array $stopWords, array $stopOps): array
    {
        $statements = [];
        $this->skipTerminators();
        while (!$this->atStop($stopWords, $stopOps)) {
            $t = $this->peek();
            // An out-of-place list terminator (keyword or operator) that is not a
            // stop at this level is unexpected: report and skip past it.
            if ($this->isUnexpectedTerminator($t)) {
                $tok = $t['type'] === 'op' ? $t['op'] : $t['word']->text;
                $this->error("unexpected token '$tok'", $t['pos']);
                $this->advance();
                $this->skipTerminators();
                continue;
            }
            $before = $this->peek();
            $stmt = $this->parseStatement();
            if ($stmt !== null) {
                $statements[] = $stmt;
            }
            // Guard against non-progress on unexpected tokens.
            if ($this->peek() === $before && $before['type'] !== 'eof') {
                $this->advance();
            }
            $this->skipTerminators();
        }

        return $statements;
    }

    /**
     * @param string[] $stopWords
     * @param string[] $stopOps
     */
    private const TERMINATOR_WORDS = ['then', 'else', 'elif', 'fi', 'do', 'done', 'esac', 'in', '}'];
    private const TERMINATOR_OPS = [')', ';;', ';&', ';;&'];

    /**
     * @param array{type: string, op?: string, word?: Word, pos: int, end: int} $t
     */
    private function isUnexpectedTerminator(array $t): bool
    {
        if ($t['type'] === 'word') {
            return in_array($t['word']->text, self::TERMINATOR_WORDS, true);
        }
        if ($t['type'] === 'op') {
            return in_array($t['op'], self::TERMINATOR_OPS, true);
        }

        return false;
    }

    private function parseCompoundList(array $stopWords, array $stopOps): Node
    {
        $startPos = $this->peek()['pos'];
        $commands = $this->parseStatements($stopWords, $stopOps);
        $endPos = $commands === [] ? $startPos : $commands[count($commands) - 1]->end;

        return new Node('CompoundList', ['pos' => $startPos, 'end' => $endPos, 'commands' => $commands]);
    }

    private function parseStatement(): ?Node
    {
        $t = $this->peek();
        $startPos = $t['pos'];

        $command = $this->parseAndOr();
        if ($command === null) {
            return null;
        }

        // Trailing redirects (applies mainly to compound commands).
        $redirects = [];
        while ($this->isRedirectStart()) {
            $redirects[] = $this->parseRedirect();
        }

        $background = false;
        $end = $command->end;
        if ($redirects !== []) {
            $end = $redirects[count($redirects) - 1]->end;
        }

        $t = $this->peek();
        if ($this->isOp($t, '&')) {
            $background = true;
            $end = $t['end'];
            $this->advance();
        } elseif ($this->isOp($t, ';')) {
            // A trailing ';' terminates but is not part of the statement span.
            $this->advance();
        }

        return new Node('Statement', [
            'pos' => $startPos,
            'end' => $end,
            'command' => $command,
            'background' => $background ?: null,
            'redirects' => $redirects,
        ]);
    }

    private function parseAndOr(): ?Node
    {
        $left = $this->parsePipeline();
        if ($left === null) {
            return null;
        }
        if (!$this->isOp($this->peek(), '&&', '||')) {
            return $left;
        }
        $commands = [$left];
        $operators = [];
        while ($this->isOp($this->peek(), '&&', '||')) {
            $op = $this->advance()['op'];
            $operators[] = $op;
            $this->skipNewlines();
            $next = $this->parsePipeline();
            if ($next === null) {
                $this->error("expected command after '$op'", $this->peek()['pos']);
                break;
            }
            $commands[] = $next;
        }

        return new Node('AndOr', [
            'pos' => $left->pos,
            'end' => $commands[count($commands) - 1]->end,
            'commands' => $commands,
            'operators' => $operators,
        ]);
    }

    private function skipNewlines(): void
    {
        while ($this->peek()['type'] === 'newline') {
            $this->advance();
        }
    }

    private function parsePipeline(): ?Node
    {
        $startPos = $this->peek()['pos'];
        $negated = false;
        $time = false;
        $seenBang = false;
        $prefixEnd = $startPos;
        while (true) {
            $t = $this->peek();
            if ($this->isWord($t, '!')) {
                if (!$seenBang) {
                    $negated = true;
                    $seenBang = true;
                    $prefixEnd = $t['end'];
                    $this->advance();
                } else {
                    // A second '!' is a syntax error; stop consuming prefixes so
                    // the remaining tokens are parsed as the (negated) command.
                    $this->error("unexpected token '!'", $t['pos']);
                    break;
                }
                continue;
            }
            if ($this->isWord($t, 'time')) {
                $time = true;
                $prefixEnd = $t['end'];
                $this->advance();
                // Consume the optional unquoted POSIX `-p` flag.
                $flag = $this->peek();
                if ($flag['type'] === 'word' && $flag['word']->text === '-p') {
                    $prefixEnd = $flag['end'];
                    $this->advance();
                }
                continue;
            }
            break;
        }

        $first = $this->parseCommand();
        $commands = $first !== null ? [$first] : [];
        $operators = [];
        while ($this->isOp($this->peek(), '|', '|&')) {
            $op = $this->advance()['op'];
            $operators[] = $op;
            $this->skipNewlines();
            $next = $this->parseCommand();
            if ($next === null) {
                $this->error("expected command after '$op'", $this->peek()['pos']);
                break;
            }
            $commands[] = $next;
        }

        if (count($commands) <= 1 && !$negated && !$time) {
            return $first;
        }

        $end = $commands === [] ? $prefixEnd : $commands[count($commands) - 1]->end;

        return new Node('Pipeline', [
            'pos' => $startPos,
            'end' => $end,
            'commands' => $commands,
            'negated' => $negated ?: null,
            'operators' => $operators,
            'time' => $time ?: null,
        ]);
    }

    // --- Commands ---------------------------------------------------------

    private function parseCommand(): ?Node
    {
        $t = $this->peek();

        if ($t['type'] === 'eof') {
            return null;
        }

        // ( subshell )  or  (( arithmetic ))
        if ($this->isOp($t, '(')) {
            $t1 = $this->peek(1);
            if ($this->isOp($t1, '(') && $t1['pos'] === $t['end']) {
                return $this->parseArithmeticCommand();
            }

            return $this->parseSubshell();
        }

        if ($t['type'] === 'word') {
            $text = $this->kwText($t);
            if ($text === '{') {
                return $this->parseBraceGroup();
            }
            if ($text === '[[') {
                return $this->parseTestCommand();
            }
            if ($text === 'if') {
                return $this->parseIf();
            }
            if ($text === 'for') {
                return $this->parseFor();
            }
            if ($text === 'while' || $text === 'until') {
                return $this->parseWhile($text);
            }
            if ($text === 'case') {
                return $this->parseCase();
            }
            if ($text === 'select') {
                return $this->parseSelect();
            }
            if ($text === 'function') {
                return $this->parseFunction();
            }
            if ($text === 'coproc') {
                return $this->parseCoproc();
            }
        }

        return $this->parseSimpleCommand();
    }

    private function parseSubshell(): Node
    {
        $open = $this->advance(); // (
        $body = $this->parseCompoundList([], [')']);
        $end = $open['end'];
        if ($this->isOp($this->peek(), ')')) {
            $end = $this->advance()['end'];
        } else {
            $this->error("expected ')'", $this->peek()['pos']);
        }

        return new Node('Subshell', ['pos' => $open['pos'], 'end' => $end, 'body' => $body]);
    }

    private function parseBraceGroup(): Node
    {
        $open = $this->advance(); // {
        $body = $this->parseCompoundList(['}'], []);
        $end = $open['end'];
        if ($this->isWord($this->peek(), '}')) {
            $end = $this->advance()['end'];
        } else {
            $this->error("expected '}'", $this->peek()['pos']);
        }

        return new Node('BraceGroup', ['pos' => $open['pos'], 'end' => $end, 'body' => $body]);
    }

    private function parseIf(): Node
    {
        $kw = $this->advance(); // if / elif

        return $this->parseIfRest($kw['pos']);
    }

    private function parseIfRest(int $pos): Node
    {
        $clause = $this->parseCompoundList(['then'], []);
        $thenFound = $this->expectWord('then') !== null;
        $then = $this->parseCompoundList(['elif', 'else', 'fi'], []);
        if ($thenFound && $then->commands === []) {
            $this->error("expected command after 'then'", $this->peek()['pos']);
        }
        $else = null;
        $end = $then->end;

        $t = $this->peek();
        if ($this->isWord($t, 'elif')) {
            $elifPos = $this->advance()['pos'];
            $else = $this->parseIfRest($elifPos);
            $end = $else->end;

            return new Node('If', ['pos' => $pos, 'end' => $end, 'clause' => $clause, 'then' => $then, 'else' => $else]);
        }
        if ($this->isWord($t, 'else')) {
            $this->advance();
            $else = $this->parseCompoundList(['fi'], []);
            $end = $else->end;
        }
        if ($this->isWord($this->peek(), 'fi')) {
            $end = $this->advance()['end'];
        } else {
            $this->error("expected 'fi'", $this->peek()['pos']);
        }

        return new Node('If', ['pos' => $pos, 'end' => $end, 'clause' => $clause, 'then' => $then, 'else' => $else]);
    }

    private function parseWhile(string $kw): Node
    {
        $start = $this->advance(); // while/until
        $clause = $this->parseCompoundList(['do'], []);
        $this->expectWord('do');
        $body = $this->parseCompoundList(['done'], []);
        $end = $this->expectWord('done') ?? $body->end;

        return new Node('While', [
            'pos' => $start['pos'],
            'end' => $end,
            'kind' => $kw,
            'clause' => $clause,
            'body' => $body,
        ]);
    }

    private function parseFor(): Node
    {
        $start = $this->advance(); // for

        // C-style arithmetic for: for (( init; test; update ))
        $t = $this->peek();
        if ($this->isOp($t, '(') && $this->isOp($this->peek(1), '(') && $this->peek(1)['pos'] === $t['end']) {
            return $this->parseArithmeticFor($start['pos']);
        }

        $name = $this->readWordToken();
        $wordlist = [];
        if ($this->isWord($this->peek(), 'in')) {
            $this->advance();
            $wordlist = $this->readWordList();
        }
        [$body, $end] = $this->parseLoopBody();

        return new Node('For', [
            'pos' => $start['pos'],
            'end' => $end,
            'name' => $name,
            'wordlist' => $wordlist,
            'body' => $body,
        ]);
    }

    private function parseArithmeticFor(int $pos): Node
    {
        // Consume the raw region between (( and )).
        $src = $this->lexer->source();
        $openStart = $this->peek()['pos'];
        // Reset lexer buffer and scan (quote-aware) for the matching )).
        $this->buf = [];
        $i = $this->lexer->findDoubleParenClose($openStart + 2);
        $inner = substr($src, $openStart + 2, $i - ($openStart + 2));
        $closeEnd = ($i < $this->end) ? $i + 2 : $i;
        // Re-point the lexer past the )) (preserving deferred state).
        $this->lexer->seek($closeEnd);
        $this->lastEnd = $closeEnd;

        [$initS, $testS, $updateS] = array_pad(explode(';', $inner, 3), 3, '');
        $ap = new ArithmeticParser($this->lexer);
        $offInit = $openStart + 2;
        $offTest = $offInit + strlen($initS) + 1;
        $offUpdate = $offTest + strlen($testS) + 1;

        [$body, $end] = $this->parseLoopBody();

        return new Node('ArithmeticFor', [
            'pos' => $pos,
            'end' => $end,
            'initialize' => trim($initS) === '' ? null : $ap->parse($initS, $offInit),
            'test' => trim($testS) === '' ? null : (new ArithmeticParser($this->lexer))->parse($testS, $offTest),
            'update' => trim($updateS) === '' ? null : (new ArithmeticParser($this->lexer))->parse($updateS, $offUpdate),
            'body' => $body,
        ]);
    }

    private function parseSelect(): Node
    {
        $start = $this->advance(); // select
        $name = $this->readWordToken();
        $wordlist = [];
        if ($this->isWord($this->peek(), 'in')) {
            $this->advance();
            $wordlist = $this->readWordList();
        }
        [$body, $end] = $this->parseLoopBody();

        return new Node('Select', [
            'pos' => $start['pos'],
            'end' => $end,
            'name' => $name,
            'wordlist' => $wordlist,
            'body' => $body,
        ]);
    }

    private function parseCase(): Node
    {
        $start = $this->advance(); // case
        $word = $this->readWordToken();
        $this->expectWord('in');
        $items = [];
        $this->skipTerminators();
        while (!$this->atStop(['esac'], []) && $this->peek()['type'] !== 'eof') {
            $items[] = $this->parseCaseItem();
            $this->skipTerminators();
        }
        $end = $this->expectWord('esac') ?? ($items === [] ? $word->end : $items[count($items) - 1]->end);

        return new Node('Case', ['pos' => $start['pos'], 'end' => $end, 'word' => $word, 'items' => $items]);
    }

    private function parseCaseItem(): Node
    {
        $startPos = $this->peek()['pos'];
        if ($this->isOp($this->peek(), '(')) {
            $this->advance();
        }
        $patterns = [];
        while (true) {
            if ($this->peek()['type'] === 'word') {
                $patterns[] = $this->advance()['word'];
            }
            if ($this->isOp($this->peek(), '|')) {
                $this->advance();
                continue;
            }
            break;
        }
        if ($this->isOp($this->peek(), ')')) {
            $this->advance();
        } else {
            $this->error("expected ')'", $this->peek()['pos']);
        }
        $body = $this->parseCompoundListCase(['esac'], []);
        $terminator = null;
        $end = $body->end;
        $t = $this->peek();
        if ($this->isOp($t, ';;', ';&', ';;&')) {
            $terminator = $t['op'];
            $end = $t['end'];
            $this->advance();
        }

        return new Node('CaseItem', [
            'pos' => $startPos,
            'end' => $end,
            'pattern' => $patterns,
            'body' => $body,
            'terminator' => $terminator,
        ]);
    }

    private function parseFunction(): Node
    {
        $start = $this->advance(); // function
        $name = $this->readWordToken();
        if ($this->isOp($this->peek(), '(') && $this->isOp($this->peek(1), ')')) {
            $this->advance();
            $this->advance();
        }
        $this->skipNewlines();
        $body = $this->parseCommand() ?? new Node('CompoundList', ['pos' => $name->end, 'end' => $name->end, 'commands' => []]);
        $redirects = [];
        while ($this->isRedirectStart()) {
            $redirects[] = $this->parseRedirect();
        }

        return new Node('Function', [
            'pos' => $start['pos'],
            'end' => $redirects === [] ? $body->end : $redirects[count($redirects) - 1]->end,
            'name' => $name,
            'body' => $body,
            'redirects' => $redirects,
        ]);
    }

    private function parseCoproc(): Node
    {
        $start = $this->advance(); // coproc
        $this->skipNewlines();
        $body = $this->parseCommand() ?? new Node('CompoundList', ['pos' => $start['end'], 'end' => $start['end'], 'commands' => []]);

        return new Node('Coproc', [
            'pos' => $start['pos'],
            'end' => $body->end,
            'name' => null,
            'body' => $body,
            'redirects' => [],
        ]);
    }

    private function parseTestCommand(): Node
    {
        $start = $this->advance(); // [[
        $expression = $this->parseTestOr();
        $end = $start['end'];
        if ($this->isWord($this->peek(), ']]')) {
            $end = $this->advance()['end'];
        } else {
            $this->error("expected ']]'", $this->peek()['pos']);
            if ($expression !== null) {
                $end = $expression->end;
            }
        }

        return new Node('TestCommand', ['pos' => $start['pos'], 'end' => $end, 'expression' => $expression]);
    }

    private function parseTestOr(): ?Node
    {
        $left = $this->parseTestAnd();
        while ($this->isOp($this->peek(), '||')) {
            $this->advance();
            $this->skipNewlines();
            $right = $this->parseTestAnd();
            $left = new Node('TestLogical', [
                'pos' => $left->pos,
                'end' => $right?->end ?? $left->end,
                'operator' => '||',
                'left' => $left,
                'right' => $right,
            ]);
        }

        return $left;
    }

    private function parseTestAnd(): ?Node
    {
        $left = $this->parseTestNot();
        while ($this->isOp($this->peek(), '&&')) {
            $this->advance();
            $this->skipNewlines();
            $right = $this->parseTestNot();
            $left = new Node('TestLogical', [
                'pos' => $left->pos,
                'end' => $right?->end ?? $left->end,
                'operator' => '&&',
                'left' => $left,
                'right' => $right,
            ]);
        }

        return $left;
    }

    private function parseTestNot(): ?Node
    {
        if ($this->isWord($this->peek(), '!')) {
            $bang = $this->advance();
            $operand = $this->parseTestNot();

            return new Node('TestNot', [
                'pos' => $bang['pos'],
                'end' => $operand?->end ?? $bang['end'],
                'operand' => $operand,
            ]);
        }

        return $this->parseTestPrimary();
    }

    private function parseTestPrimary(): ?Node
    {
        $t = $this->peek();
        if ($this->isOp($t, '(')) {
            $this->advance();
            $expr = $this->parseTestOr();
            $end = $expr?->end ?? $t['end'];
            if ($this->isOp($this->peek(), ')')) {
                $end = $this->advance()['end'];
            }

            return new Node('TestGroup', ['pos' => $t['pos'], 'end' => $end, 'expression' => $expr]);
        }

        if ($t['type'] !== 'word' || $t['word']->text === ']]') {
            return null;
        }

        // Unary operator.
        if (in_array($t['word']->text, self::TEST_UNARY, true) && $this->peek(1)['type'] === 'word'
            && $this->peek(1)['word']->text !== ']]') {
            $op = $this->advance();
            $operand = $this->advance()['word'];

            return new Node('TestUnary', [
                'pos' => $op['pos'],
                'end' => $operand->end,
                'operator' => $op['word']->text,
                'operand' => $operand,
            ]);
        }

        $left = $this->advance()['word'];
        $next = $this->peek();

        // `=~` right-hand side is a regex: parens, `|`, etc. are regex syntax,
        // so read it as a raw (whitespace-delimited) operand from the source.
        if ($next['type'] === 'word' && $next['word']->text === '=~') {
            $op = $this->advance();
            $src = $this->lexer->source();
            $i = $op['end'];
            while ($i < $this->end && ($src[$i] === ' ' || $src[$i] === "\t")) {
                $i++;
            }
            $rhsStart = $i;
            if ($i < $this->end && ($src[$i] === '"' || $src[$i] === "'")) {
                $right = $this->peek()['type'] === 'word' ? $this->advance()['word'] : $left;

                return new Node('TestBinary', [
                    'pos' => $left->pos,
                    'end' => $right->end,
                    'operator' => '=~',
                    'left' => $left,
                    'right' => $right,
                ]);
            }
            while ($i < $this->end && !ctype_space($src[$i])) {
                $i++;
            }
            $right = $this->lexer->wordFromRegion($rhsStart, $i);
            $this->lexer->seek($i);
            $this->buf = [];
            $this->lastEnd = $i;

            return new Node('TestBinary', [
                'pos' => $left->pos,
                'end' => $i,
                'operator' => '=~',
                'left' => $left,
                'right' => $right,
            ]);
        }

        $isBinaryOp = ($next['type'] === 'word' && in_array($next['word']->text, self::TEST_BINARY, true))
            || $this->isOp($next, '<', '>');
        if ($isBinaryOp) {
            $op = $next['type'] === 'op' ? $this->advance()['op'] : $this->advance()['word']->text;
            $right = $this->peek()['type'] === 'word' ? $this->advance()['word'] : $left;

            return new Node('TestBinary', [
                'pos' => $left->pos,
                'end' => $right->end,
                'operator' => $op,
                'left' => $left,
                'right' => $right,
            ]);
        }

        // Bare word test normalizes to `-n` (Bash semantics).
        return new Node('TestUnary', [
            'pos' => $left->pos,
            'end' => $left->end,
            'operator' => '-n',
            'operand' => $left,
        ]);
    }

    private function parseArithmeticCommand(): Node
    {
        $src = $this->lexer->source();
        $openStart = $this->peek()['pos'];
        $this->buf = [];
        $i = $this->lexer->findDoubleParenClose($openStart + 2);
        $inner = substr($src, $openStart + 2, $i - ($openStart + 2));
        $closeEnd = ($i < $this->end) ? $i + 2 : $i;
        $this->lexer->seek($closeEnd);
        $this->lastEnd = $closeEnd;

        $expr = (new ArithmeticParser($this->lexer))->parse($inner, $openStart + 2);

        return new Node('ArithmeticCommand', [
            'pos' => $openStart,
            'end' => $closeEnd,
            'expression' => $expr,
            'body' => $inner,
        ]);
    }

    private function parseSimpleCommand(): ?Node
    {
        $startPos = $this->peek()['pos'];
        $prefix = [];
        $name = null;
        $suffix = [];
        $redirects = [];
        $end = $startPos;

        while (true) {
            $t = $this->peek();

            if ($this->isRedirectStart()) {
                $r = $this->parseRedirect();
                $redirects[] = $r;
                $end = $r->end;
                continue;
            }

            if ($t['type'] !== 'word') {
                break;
            }

            // Function definition:  name ( )  { ... }
            if ($name === null && $prefix === [] && $suffix === []
                && $this->isOp($this->peek(1), '(') && $this->isOp($this->peek(2), ')')) {
                $nameWord = $this->advance()['word'];
                $this->advance(); // (
                $this->advance(); // )
                $this->skipNewlines();
                $body = $this->parseCommand() ?? new Node('CompoundList', ['pos' => $nameWord->end, 'end' => $nameWord->end, 'commands' => []]);
                $fnRedirects = [];
                while ($this->isRedirectStart()) {
                    $fnRedirects[] = $this->parseRedirect();
                }

                return new Node('Function', [
                    'pos' => $startPos,
                    'end' => $fnRedirects === [] ? $body->end : $fnRedirects[count($fnRedirects) - 1]->end,
                    'name' => $nameWord,
                    'body' => $body,
                    'redirects' => $fnRedirects,
                ]);
            }

            // Assignment prefix (only before the command name).
            if ($name === null && ($asg = $this->tryAssignment($t)) !== null) {
                $prefix[] = $asg;
                $end = $asg->end;
                continue;
            }

            if ($name === null) {
                $name = $this->advance()['word'];
                $end = $name->end;
            } else {
                $w = $this->advance()['word'];
                $suffix[] = $w;
                $end = $w->end;
            }
        }

        if ($name === null && $prefix === [] && $redirects === []) {
            return null;
        }

        return new Node('Command', [
            'pos' => $startPos,
            'end' => $end,
            'name' => $name,
            'prefix' => $prefix,
            'suffix' => $suffix,
            'redirects' => $redirects,
        ]);
    }

    /**
     * @param array{type: string, op?: string, word?: Word, pos: int, end: int} $t
     */
    private function tryAssignment(array $t): ?Node
    {
        $text = $t['word']->text;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(\[[^\]]*\])?(\+?)=(.*)$/s', $text, $m)) {
            return null;
        }
        $name = $m[1];
        $index = $m[2] !== '' ? substr($m[2], 1, -1) : null;
        $append = $m[3] === '+';

        // Array assignment:  name=( ... )
        if ($m[4] === '' && $this->isOp($this->peek(1), '(') && $this->peek(1)['pos'] === $t['end']) {
            $word = $this->advance()['word']; // name= token
            $this->advance(); // (
            $array = [];
            while (!$this->isOp($this->peek(), ')') && $this->peek()['type'] !== 'eof') {
                if ($this->peek()['type'] === 'word') {
                    $array[] = $this->advance()['word'];
                } elseif ($this->peek()['type'] === 'newline') {
                    $this->advance();
                } else {
                    break;
                }
            }
            $end = $word->end;
            if ($this->isOp($this->peek(), ')')) {
                $end = $this->advance()['end'];
            }

            return new Node('Assignment', [
                'pos' => $word->pos,
                'end' => $end,
                'text' => substr($this->lexer->source(), $word->pos, $end - $word->pos),
                'name' => $name,
                'value' => null,
                'append' => $append ?: null,
                'index' => $index,
                'array' => $array,
            ]);
        }

        $word = $this->advance()['word'];
        $eqOffset = strlen($name) + strlen($m[2]) + strlen($m[3]) + 1;
        $valueStart = $word->pos + $eqOffset;
        $value = $m[4] === '' ? null : $this->lexer->wordFromRegion($valueStart, $word->end);

        return new Node('Assignment', [
            'pos' => $word->pos,
            'end' => $word->end,
            'text' => $word->text,
            'name' => $name,
            'value' => $value,
            'append' => $append ?: null,
            'index' => $index,
            'array' => null,
        ]);
    }

    // --- Redirects --------------------------------------------------------

    private function isRedirectStart(): bool
    {
        $t = $this->peek();
        if ($this->isRedirectOp($t)) {
            return true;
        }
        // fd prefix:  N>  or  {var}>
        if ($t['type'] === 'word') {
            $text = $t['word']->text;
            $next = $this->peek(1);
            if ($next['pos'] === $t['end'] && $this->isRedirectOp($next)) {
                if (ctype_digit($text) || preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*\}$/', $text)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isRedirectOp(array $t): bool
    {
        return $t['type'] === 'op' && in_array($t['op'], [
            '>', '>>', '<', '<<', '<<-', '<<<', '<>', '<&', '>&', '>|', '&>', '&>>',
        ], true);
    }

    private function parseRedirect(): Node
    {
        $fd = null;
        $varName = null;
        $pos = $this->peek()['pos'];

        $t = $this->peek();
        if ($t['type'] === 'word') {
            $text = $t['word']->text;
            if (ctype_digit($text)) {
                $fd = (int) $text;
            } else {
                $varName = substr($text, 1, -1);
            }
            $this->advance();
        }

        $opTok = $this->advance();
        $operator = $opTok['op'];
        $end = $opTok['end'];

        // Locate where a target is expected (skip blanks and line continuations
        // in the raw source, so a comment or EOF is not mistaken for a target).
        $src = $this->lexer->source();
        $i = $opTok['end'];
        while ($i < $this->end) {
            if ($src[$i] === ' ' || $src[$i] === "\t") {
                $i++;
            } elseif ($src[$i] === '\\' && ($i + 1 < $this->end) && $src[$i + 1] === "\n") {
                $i += 2;
            } else {
                break;
            }
        }
        $targetPos = $i;
        $terminators = ["\n", ';', '&', '|', '(', ')', '<', '>', '#'];
        $hasTarget = $i < $this->end && !in_array($src[$i], $terminators, true);
        // `<(...)` / `>(...)` process substitutions are valid redirect targets.
        if (!$hasTarget && $i < $this->end && ($src[$i] === '<' || $src[$i] === '>')
            && ($i + 1 < $this->end) && $src[$i + 1] === '(') {
            $hasTarget = true;
        }

        $target = null;
        if ($hasTarget) {
            $this->buf = [];
            $this->lexer->seek($targetPos);
            $tw = $this->peek();
            if ($tw['type'] === 'word') {
                $target = $this->advance()['word'];
                $end = $target->end;
            } else {
                $hasTarget = false;
            }
        }
        if (!$hasTarget) {
            $this->error('expected redirect target', $targetPos);
            $this->buf = [];
            $this->lexer->seek($targetPos);
        }

        $node = new Node('Redirect', [
            'pos' => $pos,
            'end' => $end,
            'operator' => $operator,
            'target' => $target,
            'fileDescriptor' => $fd,
            'variableName' => $varName,
            'content' => null,
            'heredocQuoted' => null,
            'body' => null,
        ]);

        if (($operator === '<<' || $operator === '<<-') && $target !== null) {
            $quoted = $target->parts !== null || strpbrk($target->text, "'\"\\") !== false;
            $node->heredocQuoted = $quoted ?: null;
            $this->lexer->registerHeredoc($node, $target->value, $quoted, $operator === '<<-');
        }

        return $node;
    }

    // --- Small helpers ----------------------------------------------------

    private function readWordToken(): Word
    {
        $t = $this->peek();
        if ($t['type'] === 'word') {
            return $this->advance()['word'];
        }
        $this->error('expected word', $t['pos']);

        return new Word('', $t['pos'], $t['pos'], null, '');
    }

    /** @return Word[] */
    private function readWordList(): array
    {
        $words = [];
        while (true) {
            $t = $this->peek();
            if ($t['type'] !== 'word' || in_array($t['word']->text, ['do', 'done', 'in', '{', '}'], true)) {
                break;
            }
            $words[] = $this->advance()['word'];
        }

        return $words;
    }

    /**
     * Parse a loop body: either `do ... done` or the Bash brace form `{ ... }`.
     *
     * @return array{0: Node, 1: int} [body CompoundList, end offset]
     */
    private function parseLoopBody(): array
    {
        $this->consumeListSeparator();
        if ($this->isWord($this->peek(), '{')) {
            $group = $this->parseBraceGroup();

            return [$group->body, $group->end];
        }
        $this->expectWord('do');
        $body = $this->parseCompoundList(['done'], []);
        $end = $this->expectWord('done') ?? $body->end;

        return [$body, $end];
    }

    private function consumeListSeparator(): void
    {
        $t = $this->peek();
        if ($t['type'] === 'newline' || $this->isOp($t, ';')) {
            $this->advance();
        }
        $this->skipNewlines();
    }

    private function expectWord(string $text): ?int
    {
        if ($this->isWord($this->peek(), $text)) {
            return $this->advance()['end'];
        }
        $this->error("expected '$text'", $this->peek()['pos']);

        return null;
    }

    /**
     * Overload of parseCompoundList that can also stop at case terminators.
     *
     * @param string[] $stopWords
     * @param string[] $stopOps
     */
    private function parseCompoundListCase(array $stopWords, array $stopOps): Node
    {
        return $this->parseCompoundList($stopWords, array_merge($stopOps, [';;', ';&', ';;&']));
    }

    private function resolveDeferred(): void
    {
        foreach ($this->lexer->deferred() as $entry) {
            $part = $entry['part'];
            $depth = $entry['depth'];
            if ($part->script !== null) {
                continue;
            }
            if (!isset($part->inner) || $part->inner === null) {
                continue;
            }
            if ($depth + 1 > Lexer::MAX_NESTING) {
                continue;
            }
            $inner = $part->inner;
            if ($part->innerStart !== null) {
                $sub = new self($this->lexer->source(), $part->innerStart, $part->innerStart + strlen($inner), $depth + 1);
            } else {
                $sub = new self($inner, 0, strlen($inner), $depth + 1);
            }
            $part->script = $sub->parseScript();
            $part->inner = null;
            $part->innerStart = null;
        }
    }
}
