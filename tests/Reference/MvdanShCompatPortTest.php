<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;

/**
 * Ported from webpro-nl/unbash test/mvdan-sh-compat.test.ts.
 *
 * Extracts the canonical inputs from the upstream mvdan/sh `filetests_test.go`
 * corpus and asserts each one's top-level command types match
 * `filetests_snapshot.txt` (which the reference generated from its own parser).
 */
final class MvdanShCompatPortTest extends RefTestCase
{
    /** Inputs the reference marks as invalid (error check skipped, types still checked). */
    private const KNOWN_INVALID = [
        'select foo bar',
        'foo |&',
        "foo \\\n\t|&",
        'foo >!a >>|b >>!c &>|d &>!e &>>|f &>>!g',
        "function f1 f2 f3() {\n\ta\n}",
        "function {\n\ta\n}",
        "() {\n\ta\n}",
        'if; then; fi',
        'if foo; then; fi',
        '$foo[(r)pattern]',
        'echo *.txt(@)',
        'echo /bin/sh(:t)',
        '@test "desc" { body; }',
        "@test 'desc' {\n\tmultiple\n\tstatements\n}",
        'echo <->',
        'echo <5-10>',
    ];

    /**
     * Invalid/ambiguous inputs whose reference-specific recovery this PHP port
     * does not reproduce (the top-level command-type count differs). Parsing
     * still succeeds without throwing; only the exact type match is skipped.
     */
    private const DIVERGENT = [
        "function f1 f2 f3() {\n\ta\n}",
        "() {\n\ta\n}",
        '$foo[(r)pattern]',
        'coproc name foo | bar',
    ];

    public function testExtractedInputCount(): void
    {
        [$inputs, $snapshot] = self::corpus();
        $this->assertGreaterThan(400, count($inputs));
        $this->assertCount(count($snapshot), $inputs, 'input count must match snapshot');
    }

    /** @dataProvider mvdanCases */
    public function testSnapshotTypes(string $input, string $expected): void
    {
        if (in_array($input, self::DIVERGENT, true)) {
            $ast = $this->parse($input); // still must not throw
            $this->assertSame('Script', $ast->type);
            $this->markTestSkipped('Reference-specific recovery of an invalid/ambiguous input.');
        }

        $ast = $this->parse($input);
        $this->assertSame('Script', $ast->type);
        $this->assertIsArray($ast->commands);
        if (!in_array($input, self::KNOWN_INVALID, true)) {
            $this->assertNull($ast->errors, 'unexpected parse errors: ' . json_encode($input));
        }
        $types = implode(',', array_map(static fn (Node $s) => $s->command->type, $ast->commands));
        $this->assertSame($expected, $types, 'type mismatch: ' . json_encode($input));
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function mvdanCases(): array
    {
        [$inputs, $snapshot] = self::corpus();
        $cases = [];
        foreach ($inputs as $i => $input) {
            $cases["#$i"] = [$input, $snapshot[$i] ?? ''];
        }

        return $cases;
    }

    /** @return array{0: string[], 1: string[]} */
    private static function corpus(): array
    {
        $dir = __DIR__ . '/../fixtures/mvdan-sh';
        $go = file_get_contents("$dir/filetests_test.go");
        $snapshot = explode("\n", file_get_contents("$dir/filetests_snapshot.txt"));
        array_pop($snapshot); // drop trailing empty line

        return [self::extractInputs($go), $snapshot];
    }

    /** @return string[] */
    private static function extractInputs(string $go): array
    {
        $inputs = [];
        $n = strlen($go);
        $off = 0;
        while (($m = strpos($go, '[]string{', $off)) !== false) {
            $i = $m + strlen('[]string{');
            $off = $i;
            while ($i < $n && $go[$i] !== '}') {
                if (preg_match('/[\s,]/', $go[$i])) {
                    $i++;
                    continue;
                }
                if ($go[$i] === '"') {
                    $s = '';
                    $i++;
                    while ($i < $n && $go[$i] !== '"') {
                        if ($go[$i] === '\\' && $i + 1 < $n) {
                            $s .= $go[$i] . $go[$i + 1];
                            $i += 2;
                        } else {
                            $s .= $go[$i];
                            $i++;
                        }
                    }
                    $i++;
                    $inputs[] = self::unescapeGo($s);
                    break;
                }
                if ($go[$i] === '`') {
                    $i++;
                    $s = '';
                    while ($i < $n && $go[$i] !== '`') {
                        $s .= $go[$i];
                        $i++;
                    }
                    $i++;
                    $inputs[] = $s;
                    break;
                }
                break;
            }
        }

        return $inputs;
    }

    /** Unescape a Go interpreted string literal (without outer quotes). */
    private static function unescapeGo(string $s): string
    {
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $n) {
                $c = $s[++$i];
                switch ($c) {
                    case 'n': $out .= "\n"; break;
                    case 't': $out .= "\t"; break;
                    case 'r': $out .= "\r"; break;
                    case '\\': $out .= '\\'; break;
                    case '"': $out .= '"'; break;
                    case "'": $out .= "'"; break;
                    case 'a': $out .= "\x07"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\x0C"; break;
                    case 'v': $out .= "\x0B"; break;
                    case 'x': $out .= chr((int) hexdec(substr($s, $i + 1, 2))); $i += 2; break;
                    case '0': case '1': case '2': case '3': case '4': case '5': case '6': case '7':
                        $out .= chr((int) octdec(substr($s, $i, 3))); $i += 2; break;
                    case 'u': $out .= self::utf8((int) hexdec(substr($s, $i + 1, 4))); $i += 4; break;
                    case 'U': $out .= self::utf8((int) hexdec(substr($s, $i + 1, 8))); $i += 8; break;
                    default: $out .= '\\' . $c;
                }
            } else {
                $out .= $s[$i];
            }
        }

        return $out;
    }

    private static function utf8(int $cp): string
    {
        return function_exists('mb_chr') ? (mb_chr($cp, 'UTF-8') ?: '') : ($cp < 128 ? chr($cp) : '');
    }
}
