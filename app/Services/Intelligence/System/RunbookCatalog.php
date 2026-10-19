<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

/**
 * Read-only runbooks (brief §49): the known technical situations, the rule
 * that detects each, and ordered steps a person follows. Texts come from
 * lang/{en,am}/intelligence_system.php under `runbooks.<key>`. No step runs
 * anything from the web application; runbook execution is a separate,
 * disabled capability (IntelligenceSettings::runbookExecutionEnabled).
 */
final class RunbookCatalog
{
    /** @var array<string, array{rules: list<string>, steps: int, components: list<string>}> */
    public const RUNBOOKS = [
        'queue_backlog' => ['rules' => ['queue_backlog', 'failed_jobs'], 'steps' => 6, 'components' => ['queue']],
        'database_pressure' => ['rules' => ['db_latency', 'db_connections'], 'steps' => 6, 'components' => ['database']],
        'redis_unavailable' => ['rules' => ['redis_unavailable', 'cache_unavailable'], 'steps' => 5, 'components' => ['redis', 'cache']],
        'integration_failure' => ['rules' => ['integration_failure'], 'steps' => 5, 'components' => ['integrations']],
        'storage_full' => ['rules' => ['storage'], 'steps' => 5, 'components' => ['storage']],
        'deployment_regression' => ['rules' => ['http_error_rate', 'http_latency', 'error_repeat'], 'steps' => 6, 'components' => ['application']],
        'pdf_generation_failure' => ['rules' => ['error_repeat'], 'steps' => 5, 'components' => ['application']],
        'backup_failure' => ['rules' => ['backup'], 'steps' => 4, 'components' => ['backups']],
        'scheduler_stopped' => ['rules' => ['scheduler_missed'], 'steps' => 4, 'components' => ['scheduler']],
        'auth_failure_spike' => ['rules' => ['auth_failures'], 'steps' => 4, 'components' => ['security']],
    ];

    /** @return list<array{key: string, rules: list<string>, components: list<string>, steps: list<string>, title: string, summary: string}> */
    public static function all(): array
    {
        return array_map(fn (string $key): array => self::get($key), array_keys(self::RUNBOOKS));
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::RUNBOOKS);
    }

    /** @return array{key: string, rules: list<string>, components: list<string>, steps: list<string>, title: string, summary: string} */
    public static function get(string $key): array
    {
        $book = self::RUNBOOKS[$key];
        $steps = [];
        for ($i = 1; $i <= $book['steps']; $i++) {
            $steps[] = "intelligence.runbooks.{$key}.steps.{$i}";
        }

        return [
            'key' => $key,
            'rules' => $book['rules'],
            'components' => $book['components'],
            'steps' => $steps,
            'title' => "intelligence.runbooks.{$key}.title",
            'summary' => "intelligence.runbooks.{$key}.summary",
        ];
    }

    /** The first runbook that covers a detection rule. */
    public static function forRule(string $rule): ?string
    {
        foreach (self::RUNBOOKS as $key => $book) {
            if (in_array($rule, $book['rules'], true)) {
                return $key;
            }
        }

        return null;
    }
}
