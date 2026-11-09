<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Concerns;

/**
 * The one report shape of the design gates and gap reports: findings with a
 * severity (error / warning / info), a stable code, a translated message
 * and the subject they are about, plus a summary.
 */
trait BuildsDesignFindings
{
    /**
     * @param  array{type: string, id: string|null, code: string|null, name: string|null}  $subject
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function finding(string $severity, string $code, array $subject, array $params = []): array
    {
        $label = trim(implode(' ', array_filter([$subject['code'] ?? null, $subject['name'] ?? null])));

        return [
            'severity' => $severity,
            'code' => $code,
            'message' => __('organization-design.validation.'.$code, ['subject' => $label !== '' ? $label : '—', ...$params]),
            'subject' => $subject,
            'params' => $params,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string}
     */
    protected function report(array $findings): array
    {
        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($findings, fn ($a, $b) => [$order[$a['severity']], $a['code'], $a['subject']['code'] ?? ''] <=> [$order[$b['severity']], $b['code'], $b['subject']['code'] ?? '']);
        $counts = collect($findings)->countBy('severity');

        return [
            'ok' => ($counts['error'] ?? 0) === 0,
            'summary' => ['errors' => $counts['error'] ?? 0, 'warnings' => $counts['warning'] ?? 0, 'infos' => $counts['info'] ?? 0],
            'findings' => array_values($findings),
            'generated_at' => now()->toISOString(),
        ];
    }
}
