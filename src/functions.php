<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

if (!function_exists(__NAMESPACE__ . '\\parse')) {
    /**
     * Parse Bash source into a source-positioned AST.
     *
     * Mirrors the reference `import { parse } from "unbash"` entry point. Never
     * throws: malformed input yields a best-effort partial {@see Node} of type
     * `Script` whose `errors` property holds any {@see ParseError}s.
     */
    function parse(string $source): Node
    {
        return (new Parser($source))->parseScript();
    }
}
