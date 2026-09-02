<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * A shell word.
 *
 * `text` is the raw source slice; `value` is the dequoted/unescaped value; and
 * `parts` holds the structured expansions ({@see Node} word parts) when the
 * word contains quotes, expansions, or other structure (otherwise `null`).
 *
 * Positions are zero-based byte offsets forming a half-open `[pos, end)` range
 * in the owning script's source.
 */
final class Word implements \JsonSerializable
{
    public string $text;
    public int $pos;
    public int $end;

    /** @var Node[]|null */
    public ?array $parts;

    public string $value;

    /**
     * @param Node[]|null $parts
     */
    public function __construct(string $text, int $pos, int $end, ?array $parts, string $value)
    {
        $this->text = $text;
        $this->pos = $pos;
        $this->end = $end;
        $this->parts = $parts;
        $this->value = $value;
    }

    public function jsonSerialize(): array
    {
        $out = ['text' => $this->text, 'pos' => $this->pos, 'end' => $this->end];
        if ($this->parts !== null) {
            $out['parts'] = $this->parts;
        }
        $out['value'] = $this->value;

        return $out;
    }
}
