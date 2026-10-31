<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Application\Actions\Admin\CreateDatabaseBackupAction;
use Modules\Court\Application\Actions\Admin\SendSmtpTestEmail;
use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\SystemSetting;
use Modules\Court\Application\Services\TelegramGroupNotificationService;
use Modules\Court\Http\Support\Ui\CourtSettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * System settings on the EUISIS settings page (SystemSettings/Index, copied
 * from EUISIS): court-ms's settings described section by section by
 * CourtSettingsSchema, each section saved on its own. The database backup,
 * the database and security status and the system information are the
 * maintenance page (Court/Settings/Maintenance), linked from the settings
 * navigation. Stored secrets never reach the browser.
 */
class SystemSettingController extends Controller
{
    public function edit(Request $request): Response
    {
        $canManage = (bool) $request->user()?->hasPermission('settings.manage');

        return Inertia::render('SystemSettings/Index', [
            'settingGroups' => CourtSettingsSchema::groups(SystemSetting::current(), $canManage),
            'roles' => Role::query()->withCount('users')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Role $role): array => ['id' => (string) $role->id, 'name' => (string) $role->name, 'guard_name' => 'web', 'users_count' => (int) $role->users_count])
                ->values()->all(),
            'can' => [
                'view' => true,
                'update' => $canManage,
                'manageGeneral' => $canManage,
                'manageLocalization' => $canManage,
                'manageNotifications' => $canManage,
                'manageEmail' => $canManage,
                'manageSms' => $canManage,
                'manageTelegram' => $canManage,
                'manageSecurity' => $canManage,
                'manageAppearance' => $canManage,
                'manageIdCards' => false,
                'manageFieldWorkGps' => false,
                'manageSituationRoom' => false,
                'manageIntelligence' => false,
                'clearCache' => $canManage,
                'testChannels' => $canManage,
                'apiManagement' => false,
                'integrations' => false,
            ],
            'emailInEffect' => null,
        ]);
    }

    /** Saves one section (`system-settings.<section>.update`); the page sends that section's fields. */
    public function updateSection(Request $request, string $section): RedirectResponse
    {
        abort_unless(in_array($section, CourtSettingsSchema::sections(), true), 404);

        $data = $request->validate(CourtSettingsSchema::rules($section));
        $settings = SystemSetting::first() ?? new SystemSetting;
        CourtSettingsSchema::apply($section, $data, $request, $settings);
        $settings->save();

        // Every page reads the cached settings: drop them so the change shows at once.
        Cache::forget('court.system_settings');

        return redirect()
            ->route('court.settings.system.edit', ['tab' => $section])
            ->with('ok', [
                'key' => 'court::messages.success.updated',
                'replace' => ['resource' => __('court::messages.resources.system_settings')],
            ]);
    }

    /** The database and backup, the security status and the system information. */
    public function maintenance(): Response
    {
        $settings = SystemSetting::current();

        return Inertia::render('Court/Settings/Maintenance', [
            'databaseMetrics' => $this->databaseMetrics(),
            'securityMetrics' => $this->securityMetrics($settings),
            'systemInfo' => [
                'info_laravel' => app()->version(),
                'info_php' => PHP_VERSION,
                'info_environment' => app()->environment(),
                'info_debug' => (bool) config('app.debug'),
                'info_cache' => (string) config('cache.default'),
                'info_session' => (string) config('session.driver'),
                'info_queue' => (string) config('queue.default'),
                'info_db' => (string) config('database.default'),
                'info_timezone' => (string) config('app.timezone'),
                'info_server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function clearCache(Request $request): RedirectResponse
    {
        Cache::forget('court.system_settings');
        try {
            Artisan::call('cache:clear');
        } catch (Throwable) {
        }
        try {
            Artisan::call('view:clear');
        } catch (Throwable) {
        }

        return back()->with('ok', ['key' => 'court::settings.cache_cleared']);
    }

    /** The Email section's Test button: a test message to the signed-in administrator. */
    public function testEmail(Request $request, SendSmtpTestEmail $sendTestEmail): RedirectResponse
    {
        $recipient = (string) $request->user()?->email;
        abort_if($recipient === '', 422);

        try {
            $sendTestEmail->execute($recipient);
        } catch (Throwable $exception) {
            Log::warning('SMTP diagnostic email failed', [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', $this->smtpFailureMessage($exception));
        }

        return back()->with('ok', ['key' => 'court::settings.smtp_test_sent', 'replace' => ['email' => $recipient]]);
    }

    /** The Telegram section's Test button: a test message to the default chat. */
    public function testTelegram(TelegramGroupNotificationService $telegram): RedirectResponse
    {
        $sent = $telegram->sendTest((string) __('court::settings.telegram_test_message', ['app' => (string) (SystemSetting::current()->app_name ?? config('app.name'))]));

        return $sent
            ? back()->with('ok', ['key' => 'court::settings.telegram_test_sent'])
            : back()->with('error', __('court::settings.telegram_test_failed'));
    }

    private function smtpFailureMessage(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'authenticate'), str_contains($message, '535') => __('court::settings.smtp_test_auth_failed'),
            str_contains($message, 'connect'), str_contains($message, 'timed out'), str_contains($message, 'timeout') => __('court::settings.smtp_test_connection_failed'),
            str_contains($message, 'certificate'), str_contains($message, 'tls'), str_contains($message, 'ssl') => __('court::settings.smtp_test_tls_failed'),
            default => __('court::settings.smtp_test_failed'),
        };
    }

    public function downloadDatabaseBackup(Request $request, CreateDatabaseBackupAction $backup): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
        abort_unless($backup->supportsCurrentConnection(), 422, __('court::settings.backup_unsupported'));

        return response()->streamDownload(
            function () use ($backup): void {
                $stream = fopen('php://output', 'wb');

                if ($stream === false) {
                    return;
                }

                $backup->writeTo($stream);
            },
            $backup->filename(),
            ['Content-Type' => $backup->contentType()]
        );
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function databaseMetrics(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();

        return [
            'driver' => $driver,
            'database' => $database,
            'connection' => config('database.default'),
            'table_count' => $this->tableCount($driver, $database),
            'size' => $this->databaseSize($driver, $database),
            'migration_batch' => $this->migrationBatch(),
            'backup_supported' => in_array($driver, ['mysql', 'sqlite'], true),
        ];
    }

    private function tableCount(string $driver, string $database): int
    {
        try {
            if ($driver === 'mysql') {
                return (int) DB::selectOne(
                    'select count(*) as aggregate from information_schema.tables where table_schema = ? and table_type = ?',
                    [$database, 'BASE TABLE']
                )->aggregate;
            }

            return count(Schema::getTables());
        } catch (Throwable) {
            return 0;
        }
    }

    private function databaseSize(string $driver, string $database): ?string
    {
        try {
            $bytes = match ($driver) {
                'mysql' => (int) DB::selectOne(
                    'select coalesce(sum(data_length + index_length), 0) as bytes from information_schema.tables where table_schema = ?',
                    [$database]
                )->bytes,
                'sqlite' => $this->sqliteDatabaseSize(),
                default => null,
            };

            return $bytes === null ? null : $this->humanBytes($bytes);
        } catch (Throwable) {
            return null;
        }
    }

    private function sqliteDatabaseSize(): ?int
    {
        $databasePath = (string) config('database.connections.sqlite.database');
        $realPath = realpath($databasePath);

        if ($databasePath === ':memory:' || $realPath === false || ! is_file($realPath)) {
            return null;
        }

        return filesize($realPath) ?: null;
    }

    private function migrationBatch(): ?int
    {
        try {
            return Schema::hasTable('migrations')
                ? (int) DB::table('migrations')->max('batch')
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 2).' '.$units[$unit];
    }

    /** @return array<string, bool|int|string> */
    private function securityMetrics(SystemSetting $settings): array
    {
        $passwordStrength = min(50, max(8, (int) ($settings->password_min_length ?? 8)) * 3)
            + (($settings->password_require_uppercase ?? true) ? 15 : 0)
            + (($settings->password_require_number ?? true) ? 15 : 0)
            + (($settings->password_require_symbol ?? false) ? 20 : 0);
        $httpsEnforced = app()->environment('production') || (bool) ($settings->force_https ?? false);
        $controls = [
            'https_enforced' => $httpsEnforced,
            'debug_disabled' => ! (bool) config('app.debug'),
            'secure_cookies' => (bool) config('session.secure'),
            'strong_password' => $passwordStrength >= 80,
            'strict_lockout' => (int) ($settings->login_max_attempts ?? 5) <= 5
                && (int) ($settings->lockout_minutes ?? 15) >= 15,
            'mfa_configured' => (bool) ($settings->mfa_enabled ?? false)
                && Role::query()->where('mfa_required', true)->exists(),
        ];

        return [
            ...$controls,
            'score' => (int) round(count(array_filter($controls)) / count($controls) * 100),
            'environment' => app()->environment(),
            'session_driver' => (string) config('session.driver'),
        ];
    }
}
