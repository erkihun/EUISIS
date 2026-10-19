<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\LegalSource;
use App\Models\LegalSourceAttachment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationDesign\OrganizationDesignReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Legal sources (docs/organization-design-legacy-baseline-plan.md, ADR-LB-001):
 * the register, private documents, citations, city-level sources and the
 * scope boundary.
 */

beforeEach(function (): void {
    foreach (array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name') as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('LS Designer', 'web')->syncPermissions([
        'legal-sources.viewAny', 'legal-sources.view', 'legal-sources.create', 'legal-sources.update',
        'institution-mandates.viewAny', 'institution-mandates.view', 'institution-mandates.create', 'institution-mandates.update',
    ]);
    Role::findOrCreate('LS Catalog', 'web')->syncPermissions(['legal-sources.viewAny', 'legal-sources.view', 'legal-sources.manageCatalog']);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'name_am' => 'አዋጅ', 'sort_order' => 10]);
    Storage::fake('local');
});

function lsOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'LS-BUREAU'], ['name_en' => 'LS Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'LS-'.$suffix.'-'.uniqid(), 'name_en' => 'LS Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function lsDesigner(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('LS Designer');
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function lsPayload(Organization $organization, string $code = 'PROC-1/2020'): array
{
    return [
        'organization_id' => $organization->id, 'code' => $code, 'title_en' => 'Establishment Proclamation', 'title_am' => 'የማቋቋሚያ አዋጅ',
        'source_type_id' => LegalInstrumentType::query()->value('id'), 'issuing_authority' => 'City Council', 'document_number' => '1/2020',
        'issue_date' => '2020-01-15', 'effective_from' => '2020-02-01',
    ];
}

test('a designer records a source for their organization, attaches a document and downloads it through the controlled route only', function (): void {
    $organization = lsOrganization('A');
    $designer = lsDesigner($organization);

    $this->actingAs($designer)->post(route('organization-design.legal-sources.store'), lsPayload($organization))->assertSessionHasNoErrors()->assertRedirect();
    $source = LegalSource::query()->where('code', 'PROC-1/2020')->firstOrFail();
    expect($source->organization_id)->toBe($organization->id)->and($source->status->value)->toBe('active');

    $this->actingAs($designer)->post(route('organization-design.legal-sources.attachments.store', $source), ['file' => UploadedFile::fake()->create('proclamation.pdf', 20, 'application/pdf'), 'label' => 'Gazette copy'])
        ->assertSessionHasNoErrors();
    $this->actingAs($designer)->post(route('organization-design.legal-sources.attachments.store', $source), ['file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload')])
        ->assertSessionHasErrors('file');

    $attachment = LegalSourceAttachment::query()->where('legal_source_id', $source->id)->firstOrFail();
    Storage::disk('local')->assertExists($attachment->path);

    // The page never carries the storage path; the download streams with no-store headers.
    $page = $this->actingAs($designer)->get(route('organization-design.legal-sources.show', $source));
    $page->assertOk();
    expect($page->getContent())->not->toContain($attachment->path)->toContain('Gazette copy');
    $this->actingAs($designer)->get(route('organization-design.legal-sources.attachments.download', [$source, $attachment]))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

    // Another organization's designer sees neither the source nor the file.
    $other = lsDesigner(lsOrganization('B'));
    $this->actingAs($other)->get(route('organization-design.legal-sources.show', $source))->assertForbidden();
    $this->actingAs($other)->get(route('organization-design.legal-sources.attachments.download', [$source, $attachment]))->assertForbidden();
    $this->actingAs($other)->get(route('organization-design.legal-sources.index'))->assertOk()->assertDontSee('PROC-1/2020');
});

test('a city-level source needs the catalog grant to write and is readable in every scope', function (): void {
    $organization = lsOrganization('C');
    $designer = lsDesigner($organization);
    $catalog = User::factory()->create();
    $catalog->assignRole('LS Catalog');

    $cityPayload = [...lsPayload($organization, 'CITY-CHARTER'), 'organization_id' => null];
    $this->actingAs($designer)->post(route('organization-design.legal-sources.store'), $cityPayload)->assertForbidden();
    $this->actingAs($catalog)->post(route('organization-design.legal-sources.store'), $cityPayload)->assertSessionHasNoErrors();
    $city = LegalSource::query()->where('code', 'CITY-CHARTER')->firstOrFail();
    expect($city->organization_id)->toBeNull();

    $this->actingAs($designer)->get(route('organization-design.legal-sources.index'))->assertOk()->assertSee('CITY-CHARTER');
    $this->actingAs($designer)->get(route('organization-design.legal-sources.show', $city))->assertOk();
    $this->actingAs($designer)->put(route('organization-design.legal-sources.update', $city), ['title_en' => 'Changed'])->assertForbidden();
    $this->actingAs($catalog)->put(route('organization-design.legal-sources.update', $city), ['title_en' => 'Changed'])->assertSessionHasNoErrors();
    // The catalog manager has no business inside an institution's own sources.
    $own = LegalSource::query()->create([...lsPayload($organization, 'OWN-1'), 'status' => 'active', 'created_by' => $designer->id]);
    $this->actingAs($catalog)->put(route('organization-design.legal-sources.update', $own), ['title_en' => 'Nope'])->assertForbidden();
});

test('a mandate rests on a source through the register and a cited source cannot be removed, only superseded', function (): void {
    $organization = lsOrganization('D');
    $designer = lsDesigner($organization);
    $source = LegalSource::query()->create([...lsPayload($organization, 'PROC-9'), 'status' => 'active', 'created_by' => $designer->id]);
    $foreign = LegalSource::query()->create([...lsPayload(lsOrganization('E'), 'PROC-F'), 'status' => 'active', 'created_by' => $designer->id]);

    $this->actingAs($designer)->post(route('organization-design.mandates.store'), [
        'organization_id' => $organization->id, 'code' => 'MND-9', 'title_en' => 'Mandate', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'),
        'legal_instrument_number' => '9/2020', 'effective_from' => '2020-03-01', 'legal_source_id' => $source->id,
    ])->assertSessionHasNoErrors();
    $mandate = InstitutionMandate::query()->where('code', 'MND-9')->firstOrFail();
    expect($mandate->legal_source_id)->toBe($source->id);

    // An explicit citation from the record; a foreign organization's source is refused.
    $this->actingAs($designer)->post(route('organization-design.legal-sources.citations.store', $source), ['subject_type' => 'mandate', 'subject_id' => $mandate->id, 'note' => 'Art. 3'])->assertSessionHasNoErrors();
    $this->actingAs($designer)->post(route('organization-design.legal-sources.citations.store', $foreign), ['subject_type' => 'mandate', 'subject_id' => $mandate->id])->assertForbidden();
    expect($source->citations()->count())->toBe(1);

    $this->actingAs($designer)->delete(route('organization-design.legal-sources.destroy', $source))->assertSessionHasErrors('source');
    expect(LegalSource::query()->whereKey($source->id)->exists())->toBeTrue();

    $this->actingAs($designer)->post(route('organization-design.legal-sources.status', $source), ['status' => 'superseded', 'reason' => 'Replaced by 12/2026'])->assertSessionHasNoErrors();
    expect($source->fresh()->status->value)->toBe('superseded')
        ->and($source->citations()->count())->toBe(1);

    $citation = $source->citations()->firstOrFail();
    $this->actingAs($designer)->delete(route('organization-design.legal-sources.citations.destroy', [$source, $citation]))->assertSessionHasNoErrors();
    $this->actingAs($designer)->delete(route('organization-design.legal-sources.destroy', $source))->assertSessionHasNoErrors();
    expect(LegalSource::query()->whereKey($source->id)->exists())->toBeFalse()
        ->and(LegalSource::withTrashed()->whereKey($source->id)->exists())->toBeTrue();
});

test('the legal source report lists the city-level and in-scope sources with their document and citation counts', function (): void {
    $organization = lsOrganization('F');
    $designer = lsDesigner($organization);
    LegalSource::query()->create([...lsPayload($organization, 'R-OWN'), 'status' => 'active', 'created_by' => $designer->id]);
    LegalSource::query()->create([...lsPayload($organization, 'R-CITY'), 'organization_id' => null, 'status' => 'active', 'created_by' => $designer->id]);
    LegalSource::query()->create([...lsPayload(lsOrganization('G'), 'R-OTHER'), 'status' => 'active', 'created_by' => $designer->id]);

    $result = app(OrganizationDesignReportService::class)->run('legal_sources', $designer, []);
    $codes = collect($result['rows'])->pluck('source_code')->all();
    expect($codes)->toContain('R-OWN', 'R-CITY')->not->toContain('R-OTHER')
        ->and(collect($result['rows'])->firstWhere('source_code', 'R-CITY')['organization_code'])->toBe('CITY')
        ->and($result['columns'])->toBe(OrganizationDesignReportService::COLUMNS['legal_sources']);
});
