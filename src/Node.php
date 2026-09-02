<?php

declare(strict_types=1);

namespace ReactphpX\Unbash;

/**
 * A generic, JSON-friendly AST node.
 *
 * The reference (TypeScript) unbash represents every non-word node as a plain
 * object with a discriminating `type` field and a set of node-specific
 * properties. This class mirrors that shape: properties are accessed
 * dynamically (`$node->type`, `$node->command`, ...) and serialize to the same
 * JSON structure via {@see \JsonSerializable}.
 *
 * @property-read string $type
 */
final class Node implements \JsonSerializable
{
    public string $type;

    /** @var array<string, mixed> Ordered, node-specific properties. */
    private array $props;

    /**
     * @param array<string, mixed> $props
     */
    public function __construct(string $type, array $props = [])
    {
        $this->type = $type;
        $this->props = $props;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'type') {
            return $this->type;
        }

        return $this->props[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === 'type') {
            $this->type = $value;

            return;
        }

        $this->props[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return $name === 'type' || isset($this->props[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return $this->props;
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->type] + $this->props;
    }
}
