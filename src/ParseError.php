<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * A source-positioned parse error collected during tolerant parsing.
 *
 * unbash never throws on malformed input; it records errors here and returns a
 * best-effort partial AST. `pos` is a byte offset into the owning script's
 * source.
 */
final class ParseError implements \JsonSerializable
{
    public string $message;
    public int $pos;

    public function __construct(string $message, int $pos)
    {
        $this->message = $message;
        $this->pos = $pos;
    }

    public function jsonSerialize(): array
    {
        return ['message' => $this->message, 'pos' => $this->pos];
    }
}
