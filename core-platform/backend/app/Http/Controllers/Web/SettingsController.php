<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\SmsGateway;
use App\Settings\CoreSettingsRegistry;
use App\Settings\Euisis\SystemSettingsRegistry as EuisisRegistry;
use App\Support\CorePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The EUISIS System Settings page over the Core settings: SystemSettings/Index
 * with the EUISIS tabs, cards and fields (CoreSettingsRegistry::EUISIS_GROUPS). The Core's own rules appear in the
 * tab they belong with, in its "Other settings" card.
 */
class SettingsController extends Controller
{
    /** The EUISIS tabs the Core shows, each with the Core-only groups placed in it. */
    private const TABS = [
        'general' => ['workforce', 'numbering', 'reports', 'data_quality'],
        'localization' => [],
        'appearance' => [],
        'id_cards' => [],
        'notifications' => [],
        'email' => [],
        'sms' => [],
        'telegram' => [],
        'security' => ['files', 'audit', 'integration'],
        'field_work_gps' => [],
        'situation_room' => [],
        'intelligence' => [],
        'assistant' => [],
    ];

    /** The MFA classes of Core accounts (MfaPolicy), shown as the EUISIS "roles" of the MFA card. */
    private const MFA_ROLES = [
        'city_administrator' => ['security.mfa_city_administrators', 'City-wide administrators'],
        'organization_administrator' => ['security.mfa_organization_administrators', 'Organization administrators'],
        'officer' => ['security.mfa_officers', 'Officers'],
        'viewer' => ['security.mfa_viewers', 'Viewers'],
    ];

    /** Images and files on the General tab (EUISIS UploadSystemAssetAction). */
    private const ASSETS = ['identity_system_logo', 'favicon', 'seal', 'login_card_photo_1', 'login_card_photo_2', 'login_card_photo_3'];

    public function __construct(private readonly CoreSettings $settings) {}

    public function index(Request $request): Response
    {
        $can = $request->user()->can('system-settings.update');
        // API management and Integrations both open the Core's Integration Center.
        $integrations = $request->user()->can('integrations.view');
        $groups = [];
        foreach (self::TABS as $tab => $extras) {
            $fields = [];
            foreach ([$tab, ...$extras] as $group) {
                foreach (CoreSettingsRegistry::definitions()[$group] as $key => $definition) {
                    if ($tab === 'security' && in_array("{$group}.{$key}", array_column(self::MFA_ROLES, 0), true)) {
                        continue;
                    }
                    $fields[] = $this->field($group, $key, $definition);
                }
            }
            if ($tab === 'security') {
                array_push($fields, ...$this->mfaFields());
            }
            usort($fields, fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
            $groups[$tab] = ['fields' => $fields, 'can_manage' => $can];
        }

        return Inertia::render('SystemSettings/Index', [
            'settingGroups' => $groups,
            'emailInEffect' => $this->emailInEffect(),
            'roles' => collect(self::MFA_ROLES)->map(fn (array $role, string $id): array => [
                'id' => $id, 'name' => __($role[1]), 'guard_name' => 'web', 'users_count' => $this->mfaRoleUsers($id),
            ])->values(),
            'can' => [
                'view' => true, 'update' => $can,
                'manageGeneral' => $can, 'manageLocalization' => $can, 'manageNotifications' => $can, 'manageEmail' => $can,
                'manageSms' => $can, 'manageTelegram' => $can, 'manageSecurity' => $can, 'manageAppearance' => $can,
                'manageIdCards' => $can, 'manageFieldWorkGps' => $can, 'manageSituationRoom' => $can, 'manageIntelligence' => $can,
                'viewIdCardTemplates' => $request->user()->can('id_card_templates.view'), 'clearCache' => $can, 'testChannels' => $can,
                'apiManagement' => $integrations, 'integrations' => $integrations,
            ],
        ]);
    }

    /** Saves one tab: each field to the group it is stored in, secrets and images as EUISIS does. */
    public function update(Request $request, AuditWriter $audit, string $section): RedirectResponse
    {
        abort_unless(isset(self::TABS[$section]), 404);
        $actor = $request->user();
        $input = $request->except(['_method', 'clear', 'remove_assets']);
        $clear = array_values((array) $request->input('clear', []));
        if ($section === 'security') {
            $input = $this->mfaInput($input);
        }
        if ($section === 'general') {
            $this->assets($request, $actor);
        }

        foreach ([$section, ...self::TABS[$section]] as $group) {
            $byPageKey = [];
            foreach (CoreSettingsRegistry::definitions()[$group] as $key => $definition) {
                if (! in_array($definition['type'], ['image', 'file'], true)) {
                    $byPageKey[$definition['page_key'] ?? $key] = $key;
                }
            }
            $values = [];
            foreach ($input as $pageKey => $value) {
                if (isset($byPageKey[$pageKey])) {
                    $values[$byPageKey[$pageKey]] = $value;
                }
            }
            if ($values !== [] || $clear !== []) {
                $this->settings->update($group, $values, $actor, array_values(array_intersect($clear, array_keys($byPageKey))));
            }
        }

        return back()->with('flash', ['message' => __("settings.messages.{$section}_updated"), 'type' => 'success']);
    }

    /** EUISIS "Send test": really sends, and reports the mail server's answer. */
    /** System Settings > Clear cache: the next read comes from the table; written to the audit trail as in EUISIS. */
    public function clearCache(Request $request, AuditWriter $audit): RedirectResponse
    {
        $this->settings->clearCache();
        $audit->writeFor('settings.cache_cleared', $request->user(), 'CoreSettings', 'all', null, null, ['scope' => 'core_settings']);

        return back()->with('flash', ['message' => __('settings.messages.cache_cleared'), 'type' => 'success']);
    }

    public function testEmail(Request $request, AuditWriter $audit): RedirectResponse
    {
        $recipient = $request->string('recipient')->toString() ?: (string) $this->settings->get('email.email_test_recipient') ?: (string) $request->user()->email;
        $error = null;
        try {
            Mail::raw(__('settings.messages.test_email_body', ['app' => config('app.name')]),
                fn ($message) => $message->to($recipient)->subject(__('settings.messages.test_email_subject', ['app' => config('app.name')])));
        } catch (Throwable $exception) {
            $error = Str::limit($exception->getMessage(), 300);
        }
        $audit->writeFor('settings.channel_tested', $request->user(), 'CoreSettings', 'email', null, null, ['recipient' => $recipient, 'result' => $error === null ? 'sent' : 'failed']);
        $notSent = $error === null && config('mail.default') === 'log' ? __('settings.messages.test_email_not_sent', ['mailer' => 'log']) : null;

        return back()->with('flash', $error !== null
            ? ['message' => __('settings.messages.test_channel_failed', ['channel' => 'Email', 'error' => $error]), 'type' => 'error']
            : ['message' => $notSent ?? __('settings.messages.test_channel_sent', ['channel' => 'Email', 'target' => $recipient]), 'type' => $notSent !== null ? 'warning' : 'success']);
    }

    /** EUISIS "Send test" for SMS: really sends through the configured gateway and reports its answer. */
    public function testSms(Request $request, AuditWriter $audit, SmsGateway $gateway): RedirectResponse
    {
        $phone = $request->string('phone')->toString() ?: (string) ($this->settings->get('sms.sms_test_phone') ?? '');

        return $this->channelTest($request, $audit, 'sms', $phone, fn (string $to): ?string => $gateway->send($to, __('settings.messages.test_sms_body', ['app' => config('app.name')]))
            ? null
            : __('settings.messages.test_sms_refused'));
    }

    /** EUISIS "Send test" for Telegram: as in EUISIS, the test is recorded and queued safely; nothing is sent. */
    public function testTelegram(Request $request, AuditWriter $audit): RedirectResponse
    {
        $chatId = $request->string('chat_id')->toString() ?: (string) ($this->settings->get('telegram.telegram_test_chat_id') ?? '');

        return $this->channelTest($request, $audit, 'telegram', $chatId, null);
    }

    /** One channel test, with EUISIS's answers: missing target, queued, failed or sent; always audited. */
    private function channelTest(Request $request, AuditWriter $audit, string $channel, string $target, ?callable $send): RedirectResponse
    {
        $configured = $target !== '';
        $error = null;
        if ($configured && $send !== null) {
            try {
                $error = $send($target);
            } catch (Throwable $exception) {
                $error = Str::limit($exception->getMessage(), 300);
            }
        }
        $audit->writeFor('settings.channel_tested', $request->user(), 'CoreSettings', $channel, null, null, [
            'configured' => $configured, 'result' => ! $configured ? 'not_configured' : ($send === null ? 'queued' : ($error === null ? 'sent' : 'failed')),
        ]);
        $name = ucfirst($channel);
        if (! $configured) {
            return back()->with('flash', ['message' => __('settings.messages.test_channel_missing', ['channel' => $name]), 'type' => 'warning']);
        }
        if ($send === null) {
            return back()->with('flash', ['message' => __('settings.messages.test_channel_queued', ['channel' => $name]), 'type' => 'success']);
        }
        if ($error !== null) {
            return back()->with('flash', ['message' => __('settings.messages.test_channel_failed', ['channel' => $name, 'error' => $error]), 'type' => 'error']);
        }

        return back()->with('flash', ['message' => __('settings.messages.test_channel_sent', ['channel' => $name, 'target' => $target]), 'type' => 'success']);
    }

    /**
     * One field in the EUISIS admin payload (SystemSettingsService::getGroupForAdmin).
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function field(string $group, string $key, array $definition): array
    {
        $secret = (bool) ($definition['is_encrypted'] ?? false) || $definition['type'] === 'password';
        $asset = in_array($definition['type'], ['image', 'file'], true);
        $value = $secret || $asset ? null : $this->settings->get("{$group}.{$key}");
        $path = $asset ? $this->settings->get("{$group}.{$key}") : null;

        return [
            'key' => $definition['page_key'] ?? $key,
            'group' => $group,
            'type' => $definition['type'],
            'value' => $value,
            'is_encrypted' => (bool) ($definition['is_encrypted'] ?? false),
            'is_public' => (bool) $definition['is_public'],
            'configured' => $this->settings->configured("{$group}.{$key}"),
            'options' => $definition['options'],
            'validation_rules' => $definition['rules'],
            'description_en' => $definition['description_en'],
            'description_am' => $definition['description_am'],
            'label_en' => $definition['label_en'],
            'label_am' => $definition['label_am'],
            'sort_order' => $definition['sort_order'],
            'default' => $definition['default'],
            'is_required' => (bool) ($definition['is_required'] ?? false),
            'asset_url' => is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : null,
        ];
    }

    /**
     * The EUISIS MFA card's fields, read from the Core's per-class switches (MfaPolicy).
     *
     * @return list<array<string, mixed>>
     */
    private function mfaFields(): array
    {
        $required = array_keys(array_filter(self::MFA_ROLES, fn (array $role): bool => (bool) $this->settings->get($role[0])));
        $values = [
            'mfa_enabled' => $required !== [],
            'mfa_required_for_all' => count($required) === count(self::MFA_ROLES),
            'mfa_required_role_ids' => $required,
        ];
        $fields = [];
        foreach ($values as $key => $value) {
            $definition = EuisisRegistry::definition('security', $key);
            $fields[] = [
                'key' => $key, 'group' => 'security', 'type' => $definition['type'], 'value' => $value, 'is_encrypted' => false, 'is_public' => false,
                'configured' => true, 'options' => $key === 'mfa_required_role_ids' ? array_keys(self::MFA_ROLES) : $definition['options'],
                'validation_rules' => $definition['validation_rules'], 'description_en' => $definition['description_en'], 'description_am' => $definition['description_am'],
                'label_en' => $definition['label_en'], 'label_am' => $definition['label_am'], 'sort_order' => $definition['sort_order'],
                'default' => $definition['default'], 'is_required' => false, 'asset_url' => null,
            ];
        }

        return $fields;
    }

    /**
     * The MFA card's answers as the Core's per-class switches.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function mfaInput(array $input): array
    {
        if (! array_key_exists('mfa_enabled', $input)) {
            return $input;
        }
        $enabled = filter_var($input['mfa_enabled'], FILTER_VALIDATE_BOOL);
        $all = filter_var($input['mfa_required_for_all'] ?? false, FILTER_VALIDATE_BOOL);
        $roles = array_map('strval', (array) ($input['mfa_required_role_ids'] ?? []));
        $unknown = array_diff($roles, array_keys(self::MFA_ROLES));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['mfa_required_role_ids' => __('validation.in', ['attribute' => 'roles'])]);
        }
        foreach (self::MFA_ROLES as $id => [$setting]) {
            $input[Str::after($setting, 'security.')] = $enabled && ($all || in_array($id, $roles, true));
        }
        unset($input['mfa_enabled'], $input['mfa_required_for_all'], $input['mfa_required_role_ids']);

        return $input;
    }

    private function mfaRoleUsers(string $id): int
    {
        return User::query()->where('is_active', true)->get()->filter(function (User $user) use ($id): bool {
            $class = match (true) {
                $user->can('users.update') && $user->isCityWide() => 'city_administrator',
                $user->can('users.update') => 'organization_administrator',
                CorePermissions::changesData($user) => 'officer',
                default => 'viewer',
            };

            return $class === $id;
        })->count();
    }

    /** The General tab's images: a new upload replaces the saved one; remove_assets deletes. */
    private function assets(Request $request, User $actor): void
    {
        $remove = array_intersect((array) $request->input('remove_assets', []), self::ASSETS);
        foreach (self::ASSETS as $key) {
            $file = $request->file($key);
            if ($file instanceof UploadedFile) {
                // No SVG: these files are served from the public disk outside the application's CSP,
                // where an SVG's script would run in the Core's origin (release review, ADR 0012).
                $request->validate([$key => $key === 'favicon'
                    ? ['file', 'max:512', 'mimes:ico,png']
                    : ['image', 'max:2048', 'mimes:png,jpg,jpeg,webp']]);
                $old = $this->settings->get("general.{$key}");
                $this->settings->setAsset('general', $key, $file->store('settings', 'public'), $actor);
                if (is_string($old) && $old !== '') {
                    Storage::disk('public')->delete($old);
                }
            } elseif (in_array($key, $remove, true)) {
                $old = $this->settings->get("general.{$key}");
                $this->settings->setAsset('general', $key, null, $actor);
                if (is_string($old) && $old !== '') {
                    Storage::disk('public')->delete($old);
                }
            }
        }
    }

    /**
     * The mail settings in effect and where each comes from (EUISIS emailInEffect).
     *
     * @return array<string, array{value: string, source: string}>
     */
    private function emailInEffect(): array
    {
        $source = fn (string $key): string => $this->settings->configured("email.{$key}") ? 'settings' : 'server';
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
}
