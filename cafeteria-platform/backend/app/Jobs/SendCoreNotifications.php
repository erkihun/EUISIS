<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CoreAuditLog;
use App\Models\CoreNotification;
use App\Services\CoreSettings;
use App\Services\NotificationRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * The notifications of one audited change (ADR 0011), after it committed:
 * an in-app notification for every recipient, then e-mail and SMS where the
 * settings turn them on. A failing channel is recorded and never retried
 * into a duplicate in-app notification; the change itself is long committed.
 */
class SendCoreNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $auditLogId)
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationRouter $router, CoreSettings $settings): void
    {
        $log = CoreAuditLog::query()->find($this->auditLogId);
        if ($log === null || ! isset(NotificationRouter::RULES[$log->action])) {
            return;
        }
        [$event] = NotificationRouter::RULES[$log->action];
        $params = $this->params($log);
        $email = (bool) $settings->get('notifications.email_enabled');
        $sms = (bool) $settings->get('notifications.sms_enabled');
        $locale = (string) $settings->get('localization.default_locale') === 'am' ? 'am' : 'en';

        foreach ($router->recipients($log) as $user) {
            // One in-app notification per person and change, even if the job runs again.
            $notification = CoreNotification::query()->firstOrCreate(
                ['user_id' => $user->id, 'event' => $event, 'subject_type' => $log->subject_type, 'subject_id' => $log->subject_id, 'audit_log_id' => (string) $log->id],
                ['id' => (string) Str::uuid7(), 'params' => $params, 'url' => $this->url($log), 'created_at' => now()],
            );
            if (! $notification->wasRecentlyCreated) {
                continue;
            }
            if ($email) {
                $this->deliver($notification, 'email', function () use ($user, $event, $params, $locale): void {
                    if (trim((string) $user->email) === '') {
                        throw new \RuntimeException('The account has no e-mail address.');
                    }
                    Mail::raw(__("notifications.events.{$event}.body", $params, $locale), fn ($m) => $m->to($user->email)->subject(__("notifications.events.{$event}.title", $params, $locale)));
                });
            }
            if ($sms) {
                // The Core has no SMS gateway (ADR 0006 addendum): recorded, never pretended.
                $this->record($notification, 'sms', 'skipped', 'No SMS gateway is configured for the Core.');
            }
        }
    }

    private function deliver(CoreNotification $notification, string $channel, \Closure $send): void
    {
        try {
            $send();
            $this->record($notification, $channel, 'sent');
        } catch (Throwable $e) {
            $this->record($notification, $channel, 'failed', mb_substr($e->getMessage(), 0, 500));
        }
    }

    private function record(CoreNotification $notification, string $channel, string $status, ?string $error = null): void
    {
        DB::table('notification_deliveries')->insert(['core_notification_id' => $notification->id, 'channel' => $channel, 'status' => $status, 'error' => $error, 'attempted_at' => now()]);
    }

    /** @return array<string, string> what the templates name: numbers and codes, never personal details */
    private function params(CoreAuditLog $log): array
    {
        $new = (array) ($log->new_values ?? []);
        $number = match ($log->subject_type) {
            'Employee' => DB::table('employees')->where('id', $log->subject_id)->value('employee_number'),
            'IdCard' => DB::table('id_cards')->where('id', $log->subject_id)->value('card_number'),
            'CardRequest' => DB::table('card_requests')->join('employees', 'employees.id', '=', 'card_requests.employee_id')->where('card_requests.id', $log->subject_id)->value('employees.employee_number'),
            'HierarchyVersion' => DB::table('hierarchy_versions')->where('id', $log->subject_id)->value('version_name'),
            'ApiClient' => DB::table('api_clients')->where('uuid', $log->subject_id)->value('name'),
            'WebhookEndpoint' => DB::table('webhook_endpoints')->where('id', $log->subject_id)->value('name'),
            'ImportBatch' => DB::table('import_batches')->where('id', $log->subject_id)->value('type'),
            default => null,
        };

        return array_filter([
            'number' => (string) ($number ?? $log->subject_id ?? ''),
            'check' => (string) ($new['check_code'] ?? ''),
            'until' => isset($new['expires_at']) ? (string) $new['expires_at'] : '',
        ], fn (string $v): bool => $v !== '') + ['number' => ''];
    }

    private function url(CoreAuditLog $log): ?string
    {
        return match ($log->subject_type) {
            // Master-data pages stay in EUISIS and the Core: the platform links a record only where it serves the page.
            'Employee' => self::link('employees.show', $log->subject_id),
            'IdCard' => self::link('id-cards.show', $log->subject_id),
            'CardRequest' => self::link('card-requests.show', $log->subject_id),
            'HierarchyVersion' => self::link('hierarchy-versions.show', $log->subject_id),
            'BreakGlassGrant' => route('core.security'),
            'ImportBatch' => self::link('core.imports.show', $log->subject_id),
            'ApiClient', 'WebhookEndpoint' => route('core.integration'),
            'DataQualityCheck' => self::link('core.data-quality', ['tab' => 'critical']),
            default => null,
        };
    }

    private static function link(string $route, mixed $parameters): ?string
    {
        return Route::has($route) ? route($route, $parameters) : null;
    }
}
