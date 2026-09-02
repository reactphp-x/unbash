<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

/** Ported from webpro-nl/unbash test/roundtrip.test.ts (inline cases). */
final class RoundtripPortTest extends RefTestCase
{
    private function roundtrip(string $src): string
    {
        return $this->verify($src, $this->parse($src));
    }

    /** @dataProvider cases */
    public function testRoundtrip(string $src): void
    {
        $this->assertSame($src, $this->roundtrip($src));
    }

    /** @return array<int, array{0:string}> */
    public static function cases(): array
    {
        $srcs = [
            'echo hello world',
            "echo \"hello world\" 'foo bar'",
            'echo $HOME "${PATH}" $(date)',
            'FOO=bar BAZ=qux cmd arg1 arg2',
            'echo hello | grep h | wc -l',
            'true && echo yes || echo no',
            '! cmd | grep err',
            'if true; then echo yes; fi',
            'if a; then b; elif c; then d; else e; fi',
            'if a; then b; elif c; then d; fi >out',
            'for x in a b c; do echo $x; done',
            'for (( i = 0; i < 10; i++ )); do echo $i; done',
            'while read line; do echo $line; done',
            'until false; do echo loop; done',
            'case $x in a) echo a;; b|c) echo bc;; *) echo other;; esac',
            'select x in a b c; do echo $x; done',
            '(echo hello; echo world)',
            '{ echo hello; echo world; }',
            'function greet { echo hi; }',
            'greet() { echo hi; }',
            '[[ -f $file && $x == hello ]]',
            '(( x + y * 2 ))',
            'echo hello > /tmp/out 2>&1',
            "cat <<EOF\nhello world\nEOF\n",
            'echo a; echo b; echo c',
            "echo a\necho b\necho c\n",
            'sleep 5 &',
        ];

        return array_map(static fn ($s) => [$s], $srcs);
    }
}
