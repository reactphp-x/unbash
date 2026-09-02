<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

/** Ported from webpro-nl/unbash test/tree-sitter-compat.test.ts */
final class TreeSitterCompatPortTest extends RefTestCase
{
    /** @dataProvider corpusSections */
    public function testSectionParses(string $code): void
    {
        $ast = $this->parse($code);
        $this->assertSame('Script', $ast->type);
        $this->assertIsArray($ast->commands);
    }

    /** @return array<string, array{0:string}> */
    public static function corpusSections(): array
    {
        $dir = __DIR__ . '/../fixtures/tree-sitter-corpus';
        $cases = [];
        foreach (scandir($dir) as $file) {
            if (!str_ends_with($file, '.txt')) {
                continue;
            }
            $source = file_get_contents("$dir/$file");
            foreach (self::parseSections($source) as $j => [$name, $code]) {
                $cases["[$file] $name #$j"] = [$code];
            }
        }

        return $cases;
    }

    /**
     * @return array<int, array{0:string, 1:string}>
     */
    private static function parseSections(string $source): array
    {
        $sections = [];
        // Split on lines of >=3 '=' characters (m flag).
        $parts = preg_split('/^={3,}$/m', $source);
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $name = trim($parts[$i]);
            $body = $parts[$i + 1];
            $dashIdx = strpos($body, "\n---\n");
            $code = trim($dashIdx !== false ? substr($body, 0, $dashIdx) : $body);
            if ($code !== '') {
                $sections[] = [$name, $code];
            }
        }

        return $sections;
    }
}
