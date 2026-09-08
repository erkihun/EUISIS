<?php

declare(strict_types=1);

use App\Enums\EmploymentType;
use App\Http\Resources\IdCardResource;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardAssetResolver;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\PublicIdCheckerService;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->employee = Employee::query()->create([
        'employee_number' => 'INTERNAL-EMP-23',
        'first_name' => 'ሰላም', 'last_name' => 'ታደሰ', 'full_name' => 'ሰላም ታደሰ',
        'name_en' => 'Selam Tadesse', 'status' => 'active', 'employment_type' => 'contract',
        'gender' => 'female', 'date_of_birth' => '1990-03-15',
        'nationality' => 'Ethiopian', 'phone' => '+251911223344',
        'national_id' => 'SECRET-NATIONAL-ID', 'email' => 'private@example.test',
        'address' => 'Private address', 'emergency_contact_name' => 'Private contact',
        'emergency_contact_phone' => '+251900000001',
    ]);
    $organization = Organization::query()->create([
        'organization_type_id' => OrganizationType::query()->create(['code' => 'MAP-T', 'name_en' => 'Mapping type'])->id,
        'code' => 'MAP-ORG', 'name_en' => 'Mapping Organization', 'status' => 'active',
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $organization->id,
        'assignment_status' => 'active', 'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $this->employee->update(['current_assignment_id' => $assignment->id]);
    $this->card = IdCard::query()->create([
        'employee_id' => $this->employee->id, 'card_number' => 'PUBLIC-CARD-23',
        'status' => 'active', 'token_hash' => hash('sha256', 'mapping-token'),
        'token_version' => 1, 'qr_payload' => 'legacy-payload', 'is_current' => true,
    ]);
    app(CardQrPayloadService::class)->ensurePublicReference($this->card);
    $this->viewer = User::factory()->create();
    foreach (['cards.view', 'id-cards.previewSvg'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->viewer->givePermissionTo($permission);
    }
    $this->actingAs($this->viewer);
});

it('includes the actual employee fields in the authorized card resource', function (): void {
    $data = (new IdCardResource($this->card->fresh()->load('employee.currentAssignment')))->resolve(request());

    expect($data['card_number'])->toBe('PUBLIC-CARD-23')
        ->and($data['employee'])->toMatchArray([
            'full_name_am' => 'ሰላም ታደሰ', 'name_en' => 'Selam Tadesse',
            'gender' => 'female', 'date_of_birth' => '1990-03-15',
            'nationality' => 'Ethiopian', 'employment_type' => 'contract',
            'phone' => '+251911223344',
        ]);
});

it('supplies the same real data and configured QR URL to show and preview', function (string $routeName): void {
    config()->set('app.url', 'https://cards.example.test/base/');
    URL::forceRootUrl('https://different-request-host.test');
    try {
        $this->get(route($routeName, $this->card, false))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('card.employee.full_name', 'ሰላም ታደሰ')
                ->where('card.employee.name_en', 'Selam Tadesse')
                ->where('card.employee.nationality', 'Ethiopian')
                ->where('card.employee.employment_type', 'contract')
                ->where('card.employee.phone', '+251911223344')
                ->where('card.card_number', 'PUBLIC-CARD-23')
                ->where('card.qr_verification_url', 'https://cards.example.test/base/id-checker/'.$this->card->public_card_uuid));
    } finally {
        URL::forceRootUrl(null);
    }
})->with(['id-cards.show', 'id-cards.preview']);

it('renders each employment type and both real names in both front orientations', function (EmploymentType $type): void {
    $this->employee->update(['employment_type' => $type]);
    foreach (['landscape', 'portrait'] as $orientation) {
        $data = app(IdCardRenderDataFactory::class)->make($this->card->fresh(), $orientation);
        $svg = app(IdCardSvgRenderer::class)->renderFront($data);
        expect($data->fullNameAm)->toBe('ሰላም ታደሰ')
            ->and($data->bilingualFields[0])->toBe(['ስም', 'ሰላም ታደሰ', 'Name', 'Selam Tadesse'])
            ->and($data->bilingualFields[4][1])->toBe($type->label('am'))
            ->and($data->bilingualFields[4][3])->toBe($type->label('en'))
            // Existing card layouts abbreviate long values to fit the field.
            ->and($svg)->toContain('ሰላም ታደሰ', 'Selam Tadesse', 'Ethiopian', 'PUBLIC-CARD-23', $type === EmploymentType::DailyLabor ? 'Daily Labor' : $type->label('en'))
            ->and($svg)->not->toContain('>Active<', 'INTERNAL-EMP-23', 'SECRET-NATIONAL-ID', 'private@example.test', 'Mapping Organization');
    }
})->with(EmploymentType::cases());

it('does not invent an employment type for a legacy active employee', function (): void {
    $this->employee->update(['employment_type' => null]);
    $data = app(IdCardRenderDataFactory::class)->make($this->card->fresh());
    expect($data->bilingualFields[4][1])->toBeNull()
        ->and($data->bilingualFields[4][3])->toBeNull();
});

it('preserves an explicitly saved legacy Amharic translation across render paths', function (): void {
    $this->employee->update(['full_name' => 'Selam Tadesse', 'metadata' => ['name_am' => 'ሰላም ታደሰ']]);
    $data = app(IdCardRenderDataFactory::class)->make($this->card->fresh());
    $resource = (new IdCardResource($this->card->fresh()->load('employee.currentAssignment')))->resolve(request());
    expect($data->fullNameAm)->toBe('ሰላም ታደሰ')
        ->and($data->bilingualFields[0][1])->toBe('ሰላም ታደሰ')
        ->and($resource['employee']['full_name_am'])->toBe('ሰላም ታደሰ');
});

it('preserves every card identity and feedback token after employee and background edits', function (): void {
    Storage::fake('local');
    $token = app(EmployeeFeedbackTokenService::class)->ensureActiveToken($this->employee);
    $cardBefore = $this->card->refresh()->getRawOriginal();
    $tokenBefore = $token->refresh()->getRawOriginal();
    $urlBefore = app(CardQrPayloadService::class)->buildStableQrUrl($this->card);
    $this->employee->update(['name_en' => 'Updated Name', 'employment_type' => 'temporary', 'nationality' => 'Kenyan']);
    $path = 'id-card-templates/mapping-background.png';
    Storage::disk('local')->put($path, UploadedFile::fake()->image('background.png', 856, 540)->get());
    IdCardTemplate::query()->create([
        'name' => 'Mapping template', 'code' => 'mapping', 'orientation' => 'portrait',
        'status' => 'active', 'is_default' => true, 'front_background_path' => $path,
    ]);
    $data = app(IdCardRenderDataFactory::class)->make($this->card->fresh());
    expect($data->frontBackgroundDataUri)->toStartWith('data:image/png;base64,')
        ->and($data->qrVerificationUrl)->toBe($urlBefore)
        ->and($this->card->refresh()->getRawOriginal())->toBe($cardBefore)
        ->and($token->refresh()->getRawOriginal())->toBe($tokenBefore);
});

it('embeds a valid employee photo larger than two MB accepted by employee forms', function (): void {
    Storage::fake('public');
    $bytes = UploadedFile::fake()->image('photo.png', 400, 400)->get().str_repeat("\0", 3 * 1024 * 1024);
    Storage::disk('public')->put('photos/mapping.png', $bytes);
    $this->employee->update(['photo_path' => 'photos/mapping.png']);
    $data = app(IdCardRenderDataFactory::class)->make($this->card->fresh());
    expect($data->photoDataUri)->toBe('data:image/png;base64,'.base64_encode($bytes));
    Storage::disk('public')->put('photos/too-large.png', $bytes.str_repeat("\0", 2 * 1024 * 1024));
    expect(app(IdCardAssetResolver::class)->resolvePhotoPath('photos/too-large.png'))->toBeNull();
});

it('keeps private employee fields out of the public checker and QR payload', function (): void {
    $public = app(PublicIdCheckerService::class)->safeEmployeeInfo($this->card->fresh());
    foreach (['national_id', 'salary', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'email', 'documents', 'phone'] as $field) {
        expect($public)->not->toHaveKey($field);
    }
    $url = app(CardQrPayloadService::class)->buildStableQrUrl($this->card);
    expect($url)->toBe(rtrim(config('app.url'), '/').'/id-checker/'.$this->card->public_card_uuid)
        ->and(parse_url($url, PHP_URL_QUERY))->toBeNull();
});

it('still denies card previews to unauthorized users', function (): void {
    $this->actingAs(User::factory()->create());
    $this->get(route('id-cards.show', $this->card))->assertForbidden();
    $this->get(route('id-cards.preview', $this->card))->assertForbidden();
    $this->get(route('id-cards.preview.svg.front', $this->card))->assertForbidden();
});
