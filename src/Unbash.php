<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * Convenience facade for the {@see parse()} function.
 */
final class Unbash
{
    /**
     * Parse Bash source into a source-positioned AST ({@see Node} of type `Script`).
     */
    public static function parse(string $source): Node
    {
        return parse($source);
    }
}
