<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Settings\UpdateSystemSettingAction;
use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Actions\SystemSettings\UploadSystemAssetAction;
use App\Enums\AuditEventType;
use App\Contracts\SmsGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestNotificationChannelRequest;
use App\Http\Requests\Settings\UpdateAppearanceSettingsRequest;
use App\Http\Requests\Settings\UpdateEmailSettingsRequest;
use App\Http\Requests\Settings\UpdateFieldWorkGpsSettingsRequest;
use App\Http\Requests\Settings\UpdateGeneralSettingsRequest;
use App\Http\Requests\Settings\UpdateIdCardSettingsRequest;
use App\Http\Requests\Settings\UpdateLocalizationSettingsRequest;
use App\Http\Requests\Settings\UpdateNotificationSettingsRequest;
use App\Http\Requests\Settings\UpdateSecuritySettingsRequest;
use App\Http\Requests\Settings\UpdateSmsSettingsRequest;
use App\Http\Requests\Settings\UpdateTelegramSettingsRequest;
use App\Models\SystemSetting;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Throwable;

class SystemSettingController extends Controller
{
    public function __construct(
        private readonly SystemSettingsService $settingsService,
        private readonly UpdateSystemSettingsGroupAction $updateGroupAction,
        private readonly UploadSystemAssetAction $uploadSystemAssetAction,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function index(): Response
    {
        $this->authorize('view', SystemSetting::class);

        $user = Auth::user();

        $groups = [];
        foreach (SystemSettingsRegistry::groups() as $group) {
            // Edited under Public Site Management / Daily Activities > Settings;
            // showing them here too would give one value two editors.
            if (in_array($group, [SystemSettingsRegistry::GROUP_PUBLIC_SITE, SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, SystemSettingsRegistry::GROUP_PERFORMANCE, SystemSettingsRegistry::GROUP_GRIEVANCES], true)) {
                continue;
            }

            $fields = $this->settingsService->getGroupForAdmin($group);

            if ($group === SystemSettingsRegistry::GROUP_SECURITY) {
                // The legacy admin-only flag is enforced via backward-compat
                // mapping but is no longer editable — the role checklist below
                // replaces it.
                // The legacy shared default password is likewise read-only.
                $hidden = ['require_mfa_for_admins', 'default_password_enabled', 'default_password_hash'];
                $fields = array_values(array_filter(
                    $fields,
                    fn (array $field): bool => ! in_array($field['key'], $hidden, true),
                ));
            }

            $groups[$group] = [
                'fields' => $fields,
                // e.g. id_cards → system-settings.manageIdCards (the permission its update request checks).
                'can_manage' => $user?->can('system-settings.manage'.Str::studly($group)) ?? false,
            ];
        }

        return Inertia::render('SystemSettings/Index', [
            'settingGroups' => $groups,
            'emailInEffect' => isset($groups[SystemSettingsRegistry::GROUP_EMAIL]) ? $this->emailInEffect() : null,
            'roles' => Role::query()
                ->withCount('users')
                ->orderBy('name')
                ->get(['id', 'name', 'guard_name'])
                ->map(fn (Role $role): array => [
                    'id' => (string) $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'users_count' => (int) $role->users_count,
                ])
                ->all(),
            'can' => [
                'view' => $user?->can('system-settings.view') ?? false,
                'update' => $user?->can('system-settings.update') ?? false,
                'manageGeneral' => $user?->can('system-settings.manageGeneral') ?? false,
                'manageLocalization' => $user?->can('system-settings.manageLocalization') ?? false,
                'manageNotifications' => $user?->can('system-settings.manageNotifications') ?? false,
                'manageEmail' => $user?->can('system-settings.manageEmail') ?? false,
                'manageSms' => $user?->can('system-settings.manageSms') ?? false,
                'manageTelegram' => $user?->can('system-settings.manageTelegram') ?? false,
                'manageSecurity' => $user?->can('system-settings.manageSecurity') ?? false,
                'manageAppearance' => $user?->can('system-settings.manageAppearance') ?? false,
                'manageIdCards' => $user?->can('system-settings.manageIdCards') ?? false,
                'manageFieldWorkGps' => $user?->can('system-settings.manageFieldWorkGps') ?? false,
                'viewIdCardTemplates' => $user?->can('id_card_templates.view') ?? false,
                'clearCache' => $user?->can('system-settings.clearCache') ?? false,
                'testChannels' => $user?->can('system-settings.testNotificationChannels') ?? false,
                // Drives the API Management tab beside Security.
                'apiManagement' => $user?->can('api_management.view') ?? false,
            ],
        ]);
    }

    public function updateGeneral(UpdateGeneralSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $assets = [
            'identity_system_logo' => $request->file('identity_system_logo'),
            'favicon' => $request->file('favicon'),
            'seal' => $request->file('seal'),
        ];

        unset($validated['identity_system_logo'], $validated['favicon'], $validated['seal']);

        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_GENERAL,
            $validated,
            $request->user(),
        );

        foreach ($assets as $key => $file) {
            if ($file !== null) {
                $this->uploadSystemAssetAction->execute($key, $file, $request->user());
            }
        }

        return back()->with('flash', ['message' => __('settings.messages.general_updated'), 'type' => 'success']);
    }

    public function updateLocalization(UpdateLocalizationSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_LOCALIZATION,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.localization_updated'), 'type' => 'success']);
    }

    public function updateNotifications(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_NOTIFICATIONS,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.notifications_updated'), 'type' => 'success']);
    }

    public function updateEmail(UpdateEmailSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_EMAIL,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.email_updated'), 'type' => 'success']);
    }

    public function updateSms(UpdateSmsSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_SMS,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.sms_updated'), 'type' => 'success']);
    }

    public function updateTelegram(UpdateTelegramSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_TELEGRAM,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.telegram_updated'), 'type' => 'success']);
    }

    public function updateSecurity(UpdateSecuritySettingsRequest $request): RedirectResponse
    {
        /*
         * The shared default password can no longer be configured: new and
         * reset accounts get unique generated one-time passwords. A hash that
         * was saved earlier stays only so accounts still on it are forced to
         * change at sign-in and cannot choose it again
         * (docs/password-security-policy.md, "Migration").
         */
        $validated = $request->validated();

        // Store role ids as strings so json round-trips are type-stable.
        $validated['mfa_required_role_ids'] = array_map(
            strval(...),
            array_values($validated['mfa_required_role_ids'] ?? []),
        );

        // Saving the new role-based MFA settings retires the legacy
        // admin-only flag (its backward-compat mapping stops applying).
        if (! array_key_exists('require_mfa_for_admins', $validated)) {
            $validated['require_mfa_for_admins'] = false;
        }

        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_SECURITY,
            $validated,
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.security_updated'), 'type' => 'success']);
    }

    public function updateAppearance(UpdateAppearanceSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_APPEARANCE,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.appearance_updated'), 'type' => 'success']);
    }

    public function updateIdCards(UpdateIdCardSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_ID_CARDS,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.id_cards_updated'), 'type' => 'success']);
    }

    public function updateFieldWorkGps(UpdateFieldWorkGpsSettingsRequest $request): RedirectResponse
    {
        $this->updateGroupAction->execute(
            SystemSettingsRegistry::GROUP_FIELD_WORK_GPS,
            $request->validated(),
            $request->user(),
        );

        return back()->with('flash', ['message' => __('settings.messages.field_work_gps_updated'), 'type' => 'success']);
    }

    public function clearCache(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('system-settings.clearCache'), 403);
        $this->settingsService->clearCache();

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingsCacheCleared,
            $request->user(),
            null,
            null,
            newValues: ['scope' => 'system_settings'],
        );

        return back()->with('flash', ['message' => __('settings.messages.cache_cleared'), 'type' => 'success']);
    }

    public function testEmail(TestNotificationChannelRequest $request): RedirectResponse
    {
        // The admin pressing the button is a sensible default recipient.
        $recipient = $request->validated('recipient')
            ?: $this->settingsService->get('email', 'email_test_recipient')
            ?: $request->user()?->email;

        return $this->handleTestChannelResult(
            $request,
            channel: 'email',
            target: is_string($recipient) ? $recipient : null,
            send: function (string $to): ?string {
                Mail::raw(__('settings.messages.test_email_body', ['app' => config('app.name')]), function ($message) use ($to): void {
                    $message->to($to)->subject(__('settings.messages.test_email_subject', ['app' => config('app.name')]));
                });

                return null;
            },
        );
    }

    public function testSms(TestNotificationChannelRequest $request): RedirectResponse
    {
        $phone = $request->validated('phone') ?: $this->settingsService->get('sms', 'sms_test_phone');

        return $this->handleTestChannelResult(
            $request,
            channel: 'sms',
            target: is_string($phone) ? $phone : null,
            send: fn (string $to): ?string => app(SmsGateway::class)->send($to, __('settings.messages.test_sms_body', ['app' => config('app.name')]))
                ? null
                : __('settings.messages.test_sms_refused'),
        );
    }

    public function testTelegram(TestNotificationChannelRequest $request): RedirectResponse
    {
        $chatId = $request->validated('chat_id') ?: $this->settingsService->get('telegram', 'telegram_test_chat_id');

        return $this->handleTestChannelResult(
            $request,
            channel: 'telegram',
            target: is_string($chatId) ? $chatId : null,
        );
    }

    /**
     * Legacy per-row update preserved for backward compat.
     */
    public function update(
        Request $request,
        SystemSetting $setting,
        UpdateSystemSettingAction $action,
    ): RedirectResponse {
        $this->authorize('update', $setting);

        $request->validate(['value' => ['nullable', 'string', 'max:2000']]);

        $action->execute($setting, (string) $request->input('value', ''), $request->user());

        $this->settingsService->clearCache();

        return back()->with('flash', ['message' => __('settings.messages.setting_updated'), 'type' => 'success']);
    }

    /**
     * @param  (callable(string): ?string)|null  $send  Delivers a test message;
     *                                                   returns an error, or null when delivered.
     */
    /**
     * The mail settings this server actually uses, and where each comes from.
     * A blank field on the page falls back to the server's .env, so the form
     * alone cannot tell an administrator what the mailer will do. No secrets:
     * only whether a username and password are set.
     *
     * @return array<string, mixed>
     */
    private function emailInEffect(): array
    {
        $stored = SystemSetting::query()
            ->where('group', SystemSettingsRegistry::GROUP_EMAIL)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->pluck('key')
            ->all();
        $source = fn (string $key): string => in_array($key, $stored, true) ? 'settings' : 'server';
        $smtp = (array) config('mail.mailers.smtp');
        $scheme = (string) ($smtp['scheme'] ?? 'smtp');

        return [
            'mailer' => ['value' => (string) config('mail.default'), 'source' => $source('mail_mailer')],
            'host' => ['value' => (string) ($smtp['host'] ?? ''), 'source' => $source('mail_host')],
            'port' => ['value' => (string) ($smtp['port'] ?? ''), 'source' => $source('mail_port')],
            'connection' => ['value' => $scheme === 'smtps' ? 'ssl' : (($smtp['auto_tls'] ?? true) ? 'starttls' : 'plain'), 'source' => $source('mail_encryption')],
            'username' => ['value' => filled($smtp['username'] ?? null) ? 'set' : 'not_set', 'source' => $source('mail_username')],
            'password' => ['value' => filled($smtp['password'] ?? null) ? 'set' : 'not_set', 'source' => $source('mail_password')],
            'from' => ['value' => (string) config('mail.from.address'), 'source' => $source('mail_from_address')],
            'timeout' => ['value' => (string) ($smtp['timeout'] ?? ''), 'source' => 'server'],
        ];
    }

    private function handleTestChannelResult(
        Request $request,
        string $channel,
        ?string $target,
        ?callable $send = null,
    ): RedirectResponse {
        $configured = $target !== null && $target !== '';
        $error = null;

        // Really send, and wait for the answer: an administrator checking the
        // mail settings needs the server's reply, not a promise.
        if ($configured && $send !== null) {
            try {
                $error = $send($target);
            } catch (Throwable $exception) {
                $error = Str::limit($exception->getMessage(), 300);
                Log::warning('Notification channel test failed.', ['channel' => $channel, 'error' => $exception->getMessage()]);
            }
        }

        $this->writeAuditLogAction->execute(
            AuditEventType::NotificationChannelTested,
            $request->user(),
            null,
            null,
            newValues: [
                'channel' => $channel,
                'configured' => $configured,
                'target' => $configured ? 'configured' : 'not_configured',
                'delivered' => $configured && $send !== null ? $error === null : null,
            ],
            reason: 'system_settings_test_channel',
        );

        if (! $configured) {
            return back()->with('flash', [
                'message' => __('settings.messages.test_channel_missing', ['channel' => ucfirst($channel)]),
                'type' => 'warning',
            ]);
        }

        if ($send === null) {
            return back()->with('flash', [
                'message' => __('settings.messages.test_channel_queued', ['channel' => ucfirst($channel)]),
                'type' => 'success',
            ]);
        }

        return back()->with('flash', $error === null
            ? ['message' => __('settings.messages.test_channel_sent', ['channel' => ucfirst($channel), 'target' => $target]), 'type' => 'success']
            : ['message' => __('settings.messages.test_channel_failed', ['channel' => ucfirst($channel), 'error' => $error]), 'type' => 'error']);
    }
}
