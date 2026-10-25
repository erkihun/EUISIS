<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AuditWriter;
use App\Services\ConfigAudit;
use App\Services\Observability;
use App\Services\SystemHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Core/SystemHealth (ADR 0011): the health checks (application, database,
 * cache, storage, queue, scheduler, webhooks, API, access, audit integrity,
 * backups) from real measurements, failed and waiting jobs without their
 * payloads, operational figures and the production configuration review.
 * City-wide holders of system_health.view; retrying or removing a failed job
 * needs queue.manage and is audited.
 */
class SystemHealthController extends Controller
{
    public function show(Request $request, SystemHealth $health, Observability $observability, ConfigAudit $config): Response
    {
        $this->allow($request, 'system_health.view');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(50)->get(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']) : collect();
        $manage = $request->user()->can('queue.manage');

        return Inertia::render('Core/SystemHealth', [
            'checks' => collect($health->checks())->map(fn (array $check, string $key): array => ['key' => $key] + $check)->values(),
            'failed_jobs' => $failed->map(fn ($job): array => [
                'uuid' => $job->uuid, 'queue' => $job->queue, 'connection' => $job->connection, 'failed_at' => $job->failed_at,
                // The job's name only: payloads can carry personal data and are never shown.
                'job' => (string) (json_decode((string) $job->payload, true)['displayName'] ?? 'unknown'),
                'error' => mb_substr(strtok((string) $job->exception, "\n") ?: '', 0, 300),
                'retry_url' => $manage ? route('core.system-health.jobs.retry', $job->uuid) : null,
                'forget_url' => $manage ? route('core.system-health.jobs.forget', $job->uuid) : null,
            ])->values(),
            'observability' => $observability->summary(),
            'configuration' => $config->results(),
        ]);
    }

    public function retry(Request $request, string $uuid, AuditWriter $audit): RedirectResponse
    {
        $this->allow($request, 'queue.manage');
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $audit->writeFor('queue.job_retried', $request->user(), 'FailedJob', $uuid, null);

        return back()->with('success', __('oversight.job_retried'));
    }

    public function forget(Request $request, string $uuid, AuditWriter $audit): RedirectResponse
    {
        $this->allow($request, 'queue.manage');
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $audit->writeFor('queue.job_forgotten', $request->user(), 'FailedJob', $uuid, null);

        return back()->with('success', __('oversight.job_forgotten'));
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->isCityWide() && $request->user()->can($permission), 403);
    }
}
