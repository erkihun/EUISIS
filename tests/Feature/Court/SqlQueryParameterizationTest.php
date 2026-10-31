<?php

namespace Tests\Feature\Court;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class SqlQueryParameterizationTest extends TestCase
{
    public function test_raw_sql_calls_do_not_interpolate_variables(): void
    {
        $roots = [
            app_path(),
            database_path('migrations'),
            base_path('routes'),
        ];

        $patterns = [
            // Variables interpolated inside double-quoted raw SQL.
            '/(?:->(?:whereRaw|orWhereRaw|havingRaw|orderByRaw|groupByRaw|selectRaw)|DB::(?:raw|select|statement|unprepared))\s*\(\s*"[^"\n]*(?:\$\w+|\{\$\w+\})[^"\n]*"/',
            // SQL string concatenated with variables.
            '/(?:->(?:whereRaw|orWhereRaw|havingRaw|orderByRaw|groupByRaw|selectRaw)|DB::(?:raw|select|statement|unprepared))\s*\(\s*[\'"][^\'"\n]*[\'"]\s*\.\s*\$[A-Za-z_]\w*/',
            // Raw SQL coming directly from variables.
            '/(?:->(?:whereRaw|orWhereRaw|havingRaw|orderByRaw|groupByRaw|selectRaw)|DB::(?:select|statement|unprepared))\s*\(\s*\$[A-Za-z_]\w*/',
        ];

        $violations = [];

        foreach ($this->phpFiles($roots) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $violations[] = sprintf('%s:%d', $file, $line);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Potential non-parameterized raw SQL patterns found:\n" . implode("\n", $violations)
        );
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function phpFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }

                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
