<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CoreSetting;
use App\Models\User;
use App\Services\CoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The Core settings on the EUISIS System Settings page: the same tabs, cards and fields as EUISIS. */
class CoreSettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@core.test', 'password' => 'secret-password-1', 'role' => 'core_admin',
            'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        $this->actingAs($user, 'web');

        return $user;
    }

    /** @return array<string, list<string>> tab => field keys on the page */
    private function pageFields(): array
    {
        return collect($this->get('/system-settings')->assertOk()->inertiaProps('settingGroups'))
            ->map(fn (array $group): array => array_column($group['fields'], 'key'))->all();
    }

    public function test_the_page_has_the_euisis_tabs_with_the_euisis_fields(): void
    {
        $this->admin();
        $fields = $this->pageFields();

        $this->assertSame(['general', 'localization', 'appearance', 'id_cards', 'notifications', 'email', 'security'], array_keys($fields));
        foreach (['general' => ['support_email', 'identity_system_logo', 'help_center_url', 'login_card_photo_1'], 'localization' => ['timezone', 'date_format', 'calendar_system_mode'],
            'appearance' => ['dashboard_layout', 'default_page_size'], 'id_cards' => ['show_photo', 'verification_url', 'return_address_en'],
            'notifications' => ['email_notifications_enabled', 'sms_notifications_enabled'], 'email' => ['mail_host', 'mail_password', 'email_test_recipient'],
            'security' => ['password_min_length', 'mfa_enabled', 'mfa_required_role_ids', 'maintenance_banner_enabled']] as $tab => $keys) {
            foreach ($keys as $key) {
                $this->assertContains($key, $fields[$tab], "{$tab}.{$key}");
            }
        }
        // The Core's own rules sit with the tab they belong to; its MFA switches are the EUISIS MFA card.
        $this->assertContains('default_seat_capacity', $fields['general']);
        $this->assertContains('organization_code_format', $fields['general']);
        $this->assertContains('document_max_kb', $fields['security']);
        $this->assertNotContains('mfa_officers', $fields['security']);
        $this->assertNotContains('email_enabled', $fields['notifications']);
        $this->assertNotContains('front_bg_from', $fields['id_cards']);
    }

    public function test_the_mfa_card_saves_the_core_switches(): void
    {
        $this->admin();

        $this->patch('/system-settings/security', ['mfa_enabled' => '1', 'mfa_required_for_all' => '0', 'mfa_required_role_ids' => ['city_administrator', 'viewer']])
            ->assertSessionHasNoErrors();

        $settings = app(CoreSettings::class);
        $this->assertTrue($settings->get('security.mfa_city_administrators'));
        $this->assertFalse($settings->get('security.mfa_officers'));
        $this->assertTrue($settings->get('security.mfa_viewers'));
        $this->assertSame(['city_administrator', 'viewer'], collect($this->get('/system-settings')->inertiaProps('settingGroups.security.fields'))
            ->firstWhere('key', 'mfa_required_role_ids')['value']);
    }

    public function test_a_mail_password_is_kept_encrypted_never_shown_and_used_for_mail(): void
    {
        $this->admin();

        $this->patch('/system-settings/email', ['mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.org', 'mail_port' => 587, 'mail_encryption' => 'tls',
            'mail_password' => 'S3cret-pass', 'mail_from_address' => 'core@example.org'])->assertSessionHasNoErrors();

        $stored = CoreSetting::query()->where('group', 'email')->where('key', 'mail_password')->value('value');
        $this->assertNotSame('S3cret-pass', $stored);
        $this->assertSame('S3cret-pass', Crypt::decryptString($stored));
        $password = collect($this->get('/system-settings')->inertiaProps('settingGroups.email.fields'))->firstWhere('key', 'mail_password');
        $this->assertNull($password['value']);
        $this->assertTrue($password['configured']);

        // A blank password keeps the saved one; "clear" removes it.
        $this->patch('/system-settings/email', ['mail_password' => '', 'mail_host' => 'smtp.example.org'])->assertSessionHasNoErrors();
        $this->assertSame('S3cret-pass', app(CoreSettings::class)->get('email.mail_password'));

        Mail::raw('x', fn ($m) => $m->to('a@example.org'));
        $this->assertSame('smtp.example.org', config('mail.mailers.smtp.host'));
        $this->assertSame('core@example.org', config('mail.from.address'));

        $this->patch('/system-settings/email', ['clear' => ['mail_password']])->assertSessionHasNoErrors();
        $this->assertFalse(app(CoreSettings::class)->configured('email.mail_password'));
    }

    public function test_notification_channels_use_the_euisis_switches(): void
    {
        $this->admin();

        $this->patch('/system-settings/notifications', ['email_notifications_enabled' => '1', 'sms_notifications_enabled' => '0'])->assertSessionHasNoErrors();

        $this->assertTrue(app(CoreSettings::class)->get('notifications.email_enabled'));
    }

    public function test_the_logo_is_uploaded_and_shared_with_every_page(): void
    {
        Storage::fake('public');
        $this->admin();

        $this->post('/system-settings/general', ['_method' => 'patch', 'identity_system_logo' => UploadedFile::fake()->image('logo.png', 120, 120)])->assertSessionHasNoErrors();

        $path = app(CoreSettings::class)->get('general.identity_system_logo');
        Storage::disk('public')->assertExists($path);
        $this->assertSame(Storage::disk('public')->url($path), $this->get('/core')->inertiaProps('settings')['general.identity_system_logo_url']);

        $this->post('/system-settings/general', ['_method' => 'patch', 'remove_assets' => ['identity_system_logo']])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_an_svg_is_refused_for_the_public_images_because_it_can_carry_script(): void
    {
        // The settings images are served from the public disk (/storage), outside the application's CSP:
        // an SVG there would run its script in the Core's origin (release review, ADR 0012).
        Storage::fake('public');
        $this->admin();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';
        foreach (['favicon' => 'icon.svg', 'identity_system_logo' => 'logo.svg'] as $key => $name) {
            $this->post('/system-settings/general', ['_method' => 'patch', $key => UploadedFile::fake()->createWithContent($name, $svg)])->assertSessionHasErrors($key);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_hub_lists_the_euisis_sections(): void
    {
        $this->admin();

        $this->get('/core/settings')->assertInertia(fn (Assert $page) => $page->component('Core/Settings')
            ->where('sections.5.key', 'card_templates')->where('sections.6.key', 'code_rules')->where('sections.7.key', 'api'));
    }
}
