# unbash

Fast, zero-dependency **Bash parser for PHP**. `unbash` turns Bash source into a
typed, source-positioned AST **without executing it**.

This is a PHP port of [`webpro-nl/unbash`](https://github.com/webpro-nl/unbash)
(the original TypeScript parser by Lars Kappert). It mirrors the same AST shapes
and `parse()` API.

## Install

```bash
composer require reactphp-x/unbash
```

Requires PHP 8.1+ and has **no runtime dependencies**.

## When to use unbash

Use `unbash` when your input is Bash source — a pasted command, a whole script,
or shell embedded in JSON/YAML — and you need to inspect its structure without
running it. It returns a typed, source-positioned AST.

Example use cases:

- Classify commands, redirects, substitutions, and background execution for
  permission prompts, allowlists, or audit findings.
- Statically inventory executables and literal file/config references in package
  scripts, CI steps, and hooks.
- Attach diagnostics to generated or pasted Bash using source-positioned errors
  and partial trees.
- Build explanations or structured previews of pipelines, conditions, and
  expansions.

`unbash` does **not** execute code, perform shell expansion, provide a sandbox,
or decide whether a command is safe. It is a *tolerant* parser: malformed or
incomplete input yields a best-effort partial AST with source-positioned errors
instead of throwing.

## Usage

```php
<?php

require 'vendor/autoload.php';

use function ReactphpX\Unbash\parse;

$ast = parse('if [ -f "$1" ]; then cat "$1"; fi');
```

Result (abbreviated):

```
Script
└─ Statement
   └─ If
      ├─ clause: CompoundList [ [ -f "$1" ] ]
      └─ then:   CompoundList [ cat "$1" ]
```

`parse()` returns a `Node` of type `Script`. Every node is a
`ReactphpX\Unbash\Node` (a JSON-friendly object with a `type` discriminator and
dynamic properties). Serialize the whole tree with `json_encode($ast, ...)`.

You can also use the facade `ReactphpX\Unbash\Unbash::parse($source)`.

### Words, values, and parts

A `Word` (`ReactphpX\Unbash\Word`) holds:

- `text` — the raw source slice,
- `value` — the dequoted/unescaped value,
- `parts` — structured expansions (`Node[]`) when the word contains quotes or
  expansions, otherwise `null`,
- `pos` / `end` — byte offsets.

```php
$word = parse('echo a$(id)b')->commands[0]->command->suffix[0];

array_map(fn ($p) => $p->type, $word->parts);
// ["Literal", "CommandExpansion", "Literal"]
```

Word-part types include `Literal`, `SingleQuoted`, `DoubleQuoted`,
`AnsiCQuoted`, `LocaleString`, `SimpleExpansion`, `ParameterExpansion`,
`CommandExpansion`, `ArithmeticExpansion`, `ProcessSubstitution`,
`ExtendedGlob`, and `BraceExpansion`.

### Positions and nested scripts

Positions are byte offsets forming half-open `[pos, end)` ranges in the source.
Command and process substitutions are parsed recursively into nested `Script`
nodes whose positions are **absolute** in the original source:

```php
$src = 'echo a$(id)b';
$part = parse($src)->commands[0]->command->suffix[0]->parts[1]; // CommandExpansion
$cmd = $part->script->commands[0]->command;
substr($src, $cmd->pos, $cmd->end - $cmd->pos); // "id"
```

Parse errors inside a nested substitution surface on that nested `script`, not
on the root — check `errors` on each nested `script` while traversing.

### Errors

`parse()` never throws. Detected problems are collected on the `Script`'s
`errors` property as `ReactphpX\Unbash\ParseError` (`{ message, pos }`), and the
parser still returns a best-effort partial tree.

### Print

A basic, opinionated printer is included. It normalizes layout and does not
preserve original whitespace or comments (except the shebang):

```php
use ReactphpX\Unbash\Printer;

echo Printer::print(parse('if [ -f "$1" ]; then cat "$1"; fi'));
// if [ -f "$1" ]; then
//   cat "$1"
// fi
```

## Supported syntax

- Simple commands with assignment prefixes (`NAME=v`, `NAME+=v`, `NAME[i]=v`,
  arrays `NAME=(...)`), redirects, and suffix words.
- Pipelines (`|`, `|&`), `!` negation and `time`; and-or lists (`&&`, `||`);
  `;`/newline separation and `&` background.
- Redirects: `>`, `>>`, `<`, `<>`, `>|`, `<&`, `>&`, `&>`, `&>>`, file-descriptor
  prefixes (`2>`, `{fd}>`), here-strings (`<<<`), and heredocs (`<<`, `<<-`,
  including quoted heredocs and tab-stripping) with structured, expansion-aware
  bodies.
- Compound commands: `if`/`elif`/`else`, `for` (list and C-style arithmetic, plus
  the `{ }` body form), `while`/`until`, `case` (`;;`, `;&`, `;;&`), `select`,
  subshells `( )`, brace groups `{ }`, functions (both `name()` and `function`
  forms), and `coproc`.
- `[[ ]]` test expressions (unary/binary operators, `&&`/`||`, `!`, grouping,
  and `=~` regex operands) and `(( ))` arithmetic commands with a precedence tree.
- Word expansions: single/double quotes, `$'...'` ANSI-C, `$"..."` locale
  strings, `$var`/`${...}` parameter expansions (default/length/indirect/index/
  slice/replace), `$(...)` and backtick command substitutions, `$((...))`
  arithmetic, `<(...)`/`>(...)` process substitutions, brace expansions, and
  extended globs.

`unbash` for PHP is validated against real-world scripts (nvm-install,
rustup-init, neofetch, git-completion) which parse with zero errors.

### Notes and limitations

- Positions are byte offsets (the reference uses UTF-16 code-unit offsets); they
  coincide for ASCII input.
- Word `parts` are computed eagerly (the reference exposes them via a lazy
  getter). The resulting structure and JSON are the same.
- The parser targets Bash (much POSIX `sh` is also valid Bash). It does not
  target PowerShell, `cmd.exe`, or other shells.

## Development

```bash
composer install
composer test          # PHPUnit test suite
php examples/parse.php 'echo "$HOME" | tr a-z A-Z'
```

### Tests

The suite includes the package's own tests plus a PHP port of the **entire**
upstream [`webpro-nl/unbash`](https://github.com/webpro-nl/unbash) test suite
under `tests/Reference/` — parser, quoting, word parts, pipelines, redirects,
positions/offset, brace/extglob/process/command substitutions, parameter
expansions, compound commands, `[[ ]]` test expressions, arithmetic, assignments,
heredocs, deep nesting, error recovery, tokenizer, round-trip, plus the
corpus-driven `fixtures`, `tree-sitter-compat`, and `mvdan-sh-compat` suites (the
upstream fixture corpora are vendored under `tests/fixtures/`). The mvdan/sh
corpus is checked against `filetests_snapshot.txt` (top-level command types).

A small number of reference cases that exercise behaviors this port does not
model — some `$()` extent-scanner edge cases, escaped-backtick decoded `source`,
per-construct depth-budget error messages, and reference-specific recovery of a
few invalid/ambiguous inputs — are marked skipped with a reason.

## Credits

Port of [`webpro-nl/unbash`](https://github.com/webpro-nl/unbash) by Lars
Kappert. See that project for the reference implementation and playground.

## License

MIT
