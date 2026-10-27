<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\GenerateExport;
use App\Models\ExportRun;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report exports (ADR 0011). Asking for one needs reports.export and the
 * report's own permission; personal contact columns need
 * reports.export_sensitive and a stated purpose. Every export is queued,
 * audited when asked for, finished and downloaded, and only the person who
 * asked downloads it.
 */
final readonly class ManageExports
{
    public function __construct(private ReportCatalog $catalog, private AuditWriter $audit) {}

    /** @param array<string, mixed> $input */
    public function request(string $report, array $input, User $actor): ExportRun
    {
        abort_unless($actor->is_active && $actor->can('reports.export') && in_array($report, $this->catalog->available($actor), true), 403);
        $definition = ReportCatalog::definitions()[$report];
        $data = Validator::make($input, [
            'format' => ['required', Rule::in(ExportRun::FORMATS)],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string', Rule::in(array_keys($definition['columns']))],
            'include_sensitive' => ['sometimes', 'boolean'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'filters' => ['sometimes', 'array'],
        ])->validate();
        $sensitive = (bool) ($data['include_sensitive'] ?? false);
        if ($sensitive && ! $actor->can('reports.export_sensitive')) {
            throw ValidationException::withMessages(['include_sensitive' => 'You may not export personal contact details.']);
        }
        if ($sensitive && mb_strlen(trim((string) ($data['purpose'] ?? ''))) < 10) {
            throw ValidationException::withMessages(['purpose' => 'Say why the personal details are needed (at least 10 characters).']);
        }
        $filters = array_intersect_key((array) ($data['filters'] ?? []), array_flip($definition['filters']));
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');

        $run = ExportRun::query()->create([
            'report' => $report, 'format' => $data['format'], 'language' => app()->getLocale() === 'am' ? 'am' : 'en', 'filters' => $filters,
            'columns' => array_values($data['columns'] ?? []), 'include_sensitive' => $sensitive, 'purpose' => $data['purpose'] ?? null,
            'status' => 'queued', 'requested_by' => $actor->getKey(),
        ]);
        $this->audit->writeFor('report.export_requested', $actor, 'ExportRun', (string) $run->id, null, null,
            ['report' => $report, 'format' => $run->format, 'filters' => $filters, 'sensitive' => $sensitive], $run->purpose);
        GenerateExport::dispatch((string) $run->id)->afterCommit();

        return $run;
    }

    public function download(ExportRun $run, User $actor): StreamedResponse
    {
        abort_unless($actor->is_active && (int) $run->requested_by === (int) $actor->getKey() && $actor->can('reports.export'), 404);
        if ($run->status !== 'completed' || $run->file_path === null || ($run->expires_at !== null && $run->expires_at->isPast())) {
            throw ValidationException::withMessages(['export' => __('oversight.export_not_ready')]);
        }
        $run->forceFill(['download_count' => $run->download_count + 1, 'last_downloaded_by' => $actor->getKey(), 'last_downloaded_at' => now()])->save();
        $this->audit->writeFor('report.export_downloaded', $actor, 'ExportRun', (string) $run->id, null, null,
            ['report' => $run->report, 'rows' => $run->row_count, 'format' => $run->format, 'downloads' => $run->download_count]);
        $type = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'html' => 'text/html; charset=UTF-8'][$run->format];

        return Storage::disk((string) $run->file_disk)->download((string) $run->file_path, "core-{$run->report}-".$run->created_at?->format('Ymd-His').'.'.$run->format, [
            'Content-Type' => $type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
