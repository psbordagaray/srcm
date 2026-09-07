<?php

declare(strict_types=1);

final class StraleonRunnerGuard
{
    public static function normalizeRepositoryText(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if (! str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        return $content;
    }

    public static function assertRepositoryText(string $path): void
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Cannot read repository text: '.$path);
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            throw new RuntimeException('UTF-8 BOM forbidden: '.$path);
        }

        if (str_contains($content, "\r")) {
            throw new RuntimeException('CR/CRLF forbidden: '.$path);
        }

        if (! str_ends_with($content, "\n")) {
            throw new RuntimeException('Final LF required: '.$path);
        }
    }

    /** @return list<string> */
    public static function parseNameOnly(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $paths = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $paths[] = $line;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return list<array{status:string,path:string}> */
    public static function parsePorcelainV1(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $records = [];

        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }

            if (strlen($line) < 4 || $line[2] !== ' ') {
                throw new RuntimeException('Invalid porcelain v1 record: '.$line);
            }

            $records[] = [
                'status' => substr($line, 0, 2),
                'path' => substr($line, 3),
            ];
        }

        return $records;
    }

    public static function assertWindowsShellSafeGitRevision(
        string $revision
    ): void {
        if ($revision === '') {
            throw new RuntimeException(
                'Git revision must not be empty.'
            );
        }

        if (preg_match('/[\^&|<>()%!]/', $revision) === 1) {
            throw new RuntimeException(
                'Shell-sensitive Git revision forbidden on Windows: '
                .$revision
            );
        }
    }

    public static function firstParentRevision(
        string $revision = 'HEAD'
    ): string {
        self::assertWindowsShellSafeGitRevision($revision);

        return $revision.'~1';
    }

    public static function assertExactPaths(
        array $actual,
        array $expected,
        string $label
    ): void {
        $actual = array_values(array_unique($actual));
        $expected = array_values(array_unique($expected));

        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new RuntimeException(
                $label.' scope mismatch.'
                .PHP_EOL.'EXPECTED='.json_encode($expected, JSON_UNESCAPED_SLASHES)
                .PHP_EOL.'ACTUAL='.json_encode($actual, JSON_UNESCAPED_SLASHES)
            );
        }
    }

    public static function selfTest(): void
    {
        $expected = ['a.php', 'docs/b.md', 'z.txt'];

        self::assertExactPaths(
            self::parseNameOnly("z.txt\r\na.php\r\ndocs/b.md\r\n"),
            $expected,
            'CRLF name-only'
        );

        self::assertExactPaths(
            self::parseNameOnly("docs/b.md\nz.txt\na.php\n"),
            $expected,
            'LF name-only'
        );

        $porcelain = self::parsePorcelainV1(
            " M a.php\r\nM  b.php\r\n?? c.php\r\n"
        );

        if ($porcelain !== [
            ['status' => ' M', 'path' => 'a.php'],
            ['status' => 'M ', 'path' => 'b.php'],
            ['status' => '??', 'path' => 'c.php'],
        ]) {
            throw new RuntimeException('Porcelain semantic columns were not preserved.');
        }

        $normalized = self::normalizeRepositoryText(
            "\xEF\xBB\xBFone\r\ntwo\rthree"
        );

        if ($normalized !== "one\ntwo\nthree\n") {
            throw new RuntimeException('Repository text normalization failed.');
        }

        if (self::firstParentRevision('HEAD') !== 'HEAD~1') {
            throw new RuntimeException(
                'Safe first-parent revision construction failed.'
            );
        }

        try {
            self::assertWindowsShellSafeGitRevision('HEAD^');
            throw new RuntimeException(
                'Caret-based Git revision was not rejected.'
            );
        } catch (RuntimeException $exception) {
            if (
                $exception->getMessage()
                === 'Caret-based Git revision was not rejected.'
            ) {
                throw $exception;
            }
        }

        echo 'STRALEON_RUNNER_GUARD_SELF_TEST=GREEN'.PHP_EOL;
    }
}

if (
    PHP_SAPI === 'cli'
    && isset($argv[1])
    && $argv[1] === '--self-test'
) {
    StraleonRunnerGuard::selfTest();
}
