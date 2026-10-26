<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardTemplateVersion;
use App\Models\CoreAuditLog;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** The printed card is the EUISIS renderer's output from the designed template: preview page, SVG faces, PNG export, print sheet. */
class CoreIdCardPrintRenderTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function signIn(string $role, ?string $scopeOrganizationId = null): User
    {
        $user = User::query()->create([
            'name' => $role, 'email' => Str::random(8).'@core.test', 'password' => 'secret-password-1', 'role' => $role,
            'scope_organization_id' => $scopeOrganizationId, 'scope_type' => $scopeOrganizationId === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    private function designTemplate(): void
    {
        $this->post('/system-settings/id-card-templates', [
            'name' => 'City card', 'code' => 'CITY-2026', 'orientation' => 'landscape', 'status' => 'active', 'is_default' => '1',
            'header_config' => ['city_name_en' => 'Addis Ababa City', 'city_name_am' => 'አዲስ አበባ ከተማ'],
            'front_background' => UploadedFile::fake()->image('front.png', 1016, 640),
        ])->assertSessionHasNoErrors();
    }

    public function test_the_preview_page_and_the_printed_faces_come_from_the_designed_template(): void
    {
        Storage::fake('local');
        Storage::fake('evidence');
        $this->signIn('core_admin');
        $this->designTemplate();
        // The fixture's verified photo record, given its bytes.
        $photo = EmployeeDocument::query()->where('employee_id', $this->employee->id)->where('kind', EmployeeDocument::PHOTO)->where('is_current', true)->sole();
        Storage::disk($photo->storage_disk)->put($photo->storage_path, UploadedFile::fake()->image('photo.jpg', 40, 50)->get());

        $this->get('/id-cards/'.$this->card->id.'/preview')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('IdCards/Preview')
            ->where('card.card_number', $this->card->card_number)
            ->where('card.employee.full_name', $this->employee->full_name)
            ->where('card.employee.current_assignment.organization.name_en', $this->bureauA->name_en)
            ->where('can.print', true)
            ->has('card.qr_verification_url')
            ->has('card.employee.photo_url'));

        $front = $this->get('/id-cards/'.$this->card->id.'/preview/svg/front')->assertOk()->assertHeader('Cache-Control', 'no-cache, no-store, private');
        $this->assertStringStartsWith('image/svg+xml', (string) $front->headers->get('Content-Type'));
        $svg = $front->getContent();
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('data:image/png;base64,', $svg, 'the designed front background is drawn');
        $this->assertStringContainsString('data:image/jpeg;base64,', $svg, 'the verified photo is drawn');
        $this->assertStringContainsString($this->employee->full_name, $svg);
        $this->assertStringContainsString('Addis Ababa City', $svg);

        // The back carries the card number and the QR of the check address (ADR 0010: token only, no person data).
        $back = $this->get('/id-cards/'.$this->card->id.'/preview/svg/back')->assertOk()->getContent();
        $this->assertStringContainsString('<svg', $back);
        $this->assertStringContainsString($this->card->card_number, $back);
        $this->assertStringNotContainsString($this->employee->employee_number, $back);
        $this->assertSame(2, CoreAuditLog::query()->where('action', 'card.previewed_svg')->count());

        // PNG export needs Imagick with SVG support; without it the EUISIS answer is a plain 503, never a broken image.
        $png = $this->get('/id-cards/'.$this->card->id.'/export/png/front');
        $this->assertContains($png->getStatusCode(), [200, 503]);
        if ($png->getStatusCode() === 200) {
            $this->assertStringStartsWith("\x89PNG", $png->getContent());
        }
        $this->assertTrue(CoreAuditLog::query()->where('action', 'card.exported_png')->exists());
    }

    public function test_the_faces_follow_the_designed_orientation_and_the_version_preview_matches(): void
    {
        $this->signIn('core_admin');
        $this->post('/system-settings/id-card-templates', [
            'name' => 'Portrait card', 'code' => 'PORTRAIT', 'orientation' => 'portrait', 'status' => 'active', 'is_default' => '1',
            'header_config' => ['city_name_en' => 'Addis Ababa City'],
        ])->assertSessionHasNoErrors();

        $svg = $this->get('/id-cards/'.$this->card->id.'/preview/svg/front')->assertOk()->getContent();
        $this->assertStringContainsString('viewBox="0 0 540 856"', $svg, 'the EUISIS portrait face');

        $version = CardTemplateVersion::active();
        $sample = $this->get('/core/card-templates/versions/'.$version->id.'/preview')->assertOk()->getContent();
        // The EUISIS portrait front carries the organization, not the city band.
        $this->assertStringContainsString('Sample Bureau', $sample);
        $this->assertStringContainsString('viewBox="0 0 540 856"', $sample);
    }

    public function test_the_faces_stay_inside_the_viewer_scope(): void
    {
        $this->signIn('core_officer', (string) $this->bureauB->id);

        // A card outside the scope is not found at all (the Core's scoped route binding).
        $this->get('/id-cards/'.$this->card->id.'/preview/svg/front')->assertNotFound();
        $this->get('/id-cards/'.$this->card->id.'/export/png/front')->assertNotFound();
        $this->assertFalse(CoreAuditLog::query()->where('action', 'card.previewed_svg')->exists());

        $this->signIn('core_viewer', (string) $this->bureauA->id);
        $this->get('/id-cards/'.$this->card->id.'/preview/svg/front')->assertOk();
    }
}
