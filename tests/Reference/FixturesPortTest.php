<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

/** Ported from webpro-nl/unbash test/fixtures.test.ts */
final class FixturesPortTest extends RefTestCase
{
    /** @dataProvider fixtureFiles */
    public function testFixtureParses(string $path): void
    {
        $source = file_get_contents($path);
        $ast = $this->parse($source);
        $this->assertSame('Script', $ast->type);
        $this->assertIsArray($ast->commands);
        $this->assertGreaterThan(0, count($ast->commands), 'expected at least one command');
    }

    /** @return array<string, array{0:string}> */
    public static function fixtureFiles(): array
    {
        $base = __DIR__ . '/../fixtures';
        $cases = [];
        foreach (scandir($base) as $sub) {
            if ($sub === '.' || $sub === '..' || $sub === 'tree-sitter-corpus') {
                continue;
            }
            $dir = "$base/$sub";
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $cases["[$sub] $file"] = ["$dir/$file"];
            }
        }

        return $cases;
    }
}
