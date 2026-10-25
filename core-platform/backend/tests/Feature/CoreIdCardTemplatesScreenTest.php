<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardTemplateVersion;
use App\Models\IdCardTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The EUISIS ID card template designer on the Core: the same page, rules and card presentation. */
class CoreIdCardTemplatesScreenTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role): User
    {
        $user = User::query()->create(['name' => $role, 'email' => $role.'@core.test', 'password' => 'secret-password-1', 'role' => $role,
            'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        $this->actingAs($user, 'web');

        return $user;
    }

    /** @return array<string, mixed> */
    private function template(string $code, array $extra = []): array
    {
        return ['name' => 'City card', 'code' => $code, 'orientation' => 'landscape', 'status' => 'active', 'is_default' => '1',
            'header_config' => ['city_name_en' => 'Addis Ababa', 'city_name_am' => 'አዲስ አበባ'], ...$extra];
    }

    public function test_the_page_is_the_euisis_designer_with_its_props(): void
    {
        $this->signIn('core_admin');

        $this->get('/system-settings/id-card-templates')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SystemSettings/IdCardTemplates')
            ->has('templates', 0)
            ->where('can.create', true)->where('can.set_default', true)
            ->has('uploadLimitMb'));
        $this->get('/core/card-templates')->assertRedirect('/system-settings/id-card-templates');
    }

    public function test_a_template_is_designed_with_a_background_and_becomes_the_card_presentation(): void
    {
        Storage::fake('local');
        $this->signIn('core_admin');

        $this->post('/system-settings/id-card-templates', $this->template('CITY-2026', [
            'front_background' => UploadedFile::fake()->image('front.png', 1016, 640),
            'layout_config' => ['front' => ['photo' => ['x' => 5, 'y' => 10, 'w' => 25, 'h' => 60]]],
        ]))->assertSessionHasNoErrors();

        $template = IdCardTemplate::query()->where('code', 'CITY-2026')->sole();
        $this->assertTrue($template->is_default);
        Storage::disk('local')->assertExists($template->getAttributes()['front_background_path']);

        $presentation = $this->get('/id-cards')->assertOk()->inertiaProps('idCardTemplate');
        $this->assertSame($template->id, $presentation['id']);
        $this->assertSame('Addis Ababa', $presentation['header_config']['city_name_en']);
        $this->assertSame(25.0, (float) $presentation['layout_config']['front']['photo']['w']);
        $this->assertStringContainsString('/background/front', $presentation['front_background_url']);
        $this->get($presentation['front_background_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($template->id, $this->get('/id-cards')->inertiaProps('idCardTemplates')['landscape']['id']);
    }

    public function test_the_euisis_rules_hold_and_the_default_follows_the_designer(): void
    {
        $this->signIn('core_admin');
        $this->post('/system-settings/id-card-templates', $this->template('A'))->assertSessionHasNoErrors();
        $this->post('/system-settings/id-card-templates', $this->template('B', ['status' => 'inactive']))->assertSessionHasErrors('status');
        $this->post('/system-settings/id-card-templates', $this->template('B', ['layout_config' => ['front' => ['photo' => ['x' => 80, 'y' => 0, 'w' => 30, 'h' => 10]]]]))
            ->assertSessionHasErrors('layout_config.front.photo.w');
        $this->post('/system-settings/id-card-templates', $this->template('B', ['front_background' => UploadedFile::fake()->create('front.svg', 10, 'image/svg+xml')]))
            ->assertSessionHasErrors('front_background');

        $this->post('/system-settings/id-card-templates', $this->template('B', ['is_default' => '0']))->assertSessionHasNoErrors();
        $b = IdCardTemplate::query()->where('code', 'B')->sole();
        $this->post('/system-settings/id-card-templates/'.$b->id.'/default')->assertSessionHasNoErrors();
        $this->assertTrue($b->fresh()->is_default);
        $this->assertFalse(IdCardTemplate::query()->where('code', 'A')->sole()->is_default);

        $this->delete('/system-settings/id-card-templates/'.$b->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted('id_card_templates', ['id' => $b->id]);
    }

    public function test_the_default_template_is_the_active_print_template(): void
    {
        $this->signIn('core_admin');
        $before = CardTemplateVersion::active();

        $this->post('/system-settings/id-card-templates', $this->template('CITY-2026', ['orientation' => 'portrait']))->assertSessionHasNoErrors();

        $active = CardTemplateVersion::active();
        $this->assertNotSame($before?->id, $active->id);
        $this->assertSame('CITY-2026', $active->template->code);
        $this->assertSame('portrait', $active->config['orientation']);
        $this->assertSame('Addis Ababa', $active->config['header_en']);
        $this->assertSame(1, CardTemplateVersion::query()->where('status', 'active')->count());
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'card_template.mirrored']);

        // Saving the same shape again does not make another version.
        $template = IdCardTemplate::query()->where('code', 'CITY-2026')->sole();
        $this->patch('/system-settings/id-card-templates/'.$template->id, $this->template('CITY-2026', ['orientation' => 'portrait', 'description' => 'For 2026']))->assertSessionHasNoErrors();
        $this->assertSame($active->id, CardTemplateVersion::active()->id);
    }

    public function test_only_an_administrator_designs(): void
    {
        $this->signIn('core_officer');

        $this->get('/system-settings/id-card-templates')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.create', false));
        $this->post('/system-settings/id-card-templates', $this->template('X'))->assertForbidden();
    }
}
