<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

/** Ported from webpro-nl/unbash test/parser.test.ts */
final class ParserPortTest extends RefTestCase
{
    public function testSimpleCommand(): void
    {
        $c = $this->getCmd($this->parse('echo hello'));
        $this->assertSame('echo', $c->name->text);
        $this->assertSame(['hello'], $this->args($c));
    }

    public function testEmptyCommandCollectionsAreOwned(): void
    {
        $first = $this->parse('echo')->commands[0]->command;
        $second = $this->parse('date')->commands[0]->command;
        $this->assertSame('Command', $first->type);
        $this->assertSame('Command', $second->type);
        $this->assertSame([], $first->suffix);
        $this->assertSame([], $second->suffix);
        $this->assertNotNull($first->name);
    }

    public function testEmptyStatementRedirectsAreOwned(): void
    {
        $statements = $this->parse('echo; date')->commands;
        $this->assertSame([], $statements[0]->redirects);
        $this->assertSame([], $statements[1]->redirects);
    }

    public function testCommandWithFlags(): void
    {
        $c = $this->getCmd($this->parse('program -short --long args'));
        $this->assertSame('program', $c->name->text);
        $this->assertSame(['-short', '--long', 'args'], $this->args($c));
    }

    public function testShebangSkipped(): void
    {
        $ast = $this->parse("#!/bin/sh\necho hello");
        $this->assertCount(1, $ast->commands);
        $this->assertSame('echo', $this->getCmd($ast)->name->text);
    }

    public function testSemicolonsSeparate(): void
    {
        $this->assertCount(3, $this->parse('cmd1; cmd2; cmd3')->commands);
    }

    public function testNewlinesSeparate(): void
    {
        $this->assertCount(2, $this->parse("cmd1\ncmd2")->commands);
    }

    public function testDoubleDashPreserved(): void
    {
        $c = $this->getCmd($this->parse('exec -y -- program2'));
        $this->assertSame('exec', $c->name->text);
        $this->assertContains('--', $this->args($c));
    }

    public function testSetAndTrapCommands(): void
    {
        $ast = $this->parse('set +e' . "\n" . 'trap cleanup EXIT' . "\n" . 'trap "echo interrupted" INT TERM' . "\n" . 'set -e');
        $this->assertCount(4, $ast->commands);
    }

    public function testObjectPrototypeMemberNamesAreOrdinary(): void
    {
        foreach (['toString', 'valueOf', 'constructor', 'hasOwnProperty', '__proto__', 'isPrototypeOf'] as $name) {
            $ast = $this->parse("$name arg");
            $this->assertCount(1, $ast->commands, "no command for: $name");
            $this->assertSame($name, $this->getCmd($ast)->name->text);
            $this->assertSame(['arg'], $this->args($this->getCmd($ast)));
        }
    }

    public function testObjectPrototypeNameDoesNotTruncate(): void
    {
        $ast = $this->parse('echo hi; toString foo; echo bye');
        $this->assertCount(3, $ast->commands);
        $this->assertSame('echo', $this->getCmd($ast, 2)->name->text);
    }

    public function testRealWorldScriptsParseWithoutErrors(): void
    {
        $scripts = [
            'curl -fsSL https://get.pnpm.io/install.sh | SHELL=`which bash` bash -',
            'find smoke/docs -type d -mindepth 1 -maxdepth 1 -exec rm -rf {} +',
            "find . -name '*.txt' | xargs -I {} sh -c 'echo Processing {}; cat {}'",
            "echo '\$JSON' | jq -e '.[] | select(.name == \"pkg\")' > /dev/null",
            'git diff --quiet || git commit -anm "release: $VERSION"',
            "if [ -f .changeset/pre.json ]; then\n    pre=\$(jq -r \".tag\" .changeset/pre.json)\n    echo \"value=\$pre\" >> \$GITHUB_OUTPUT\nfi",
            "docker exec postgres-postgres-1 bash -c 'pg_dumpall -U postgres -s' > schema-before",
            'biome ci --formatter-enabled=false --reporter=github && eslint . --concurrency=auto && knip',
            'changeset version && node ./scripts/deps/update-example-versions.js && pnpm install --no-frozen-lockfile',
            'turbo run build --filter=astro --filter=create-astro --filter="@astrojs/*"',
            'cat file | grep pattern | sort -u | tee output.txt | wc -l > count.txt',
            "declare -A map\nmap[key]=\"value\"\necho \"\${map[key]}\"",
        ];
        foreach ($scripts as $script) {
            $ast = $this->parse($script);
            $this->assertSame('Script', $ast->type, 'Failed: ' . substr($script, 0, 60));
            $this->assertGreaterThan(0, count($ast->commands), 'No commands: ' . substr($script, 0, 60));
        }
    }
}
