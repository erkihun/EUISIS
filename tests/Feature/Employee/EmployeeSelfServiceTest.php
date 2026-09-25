<?php

use App\Contracts\SmsGateway;
use App\Enums\CardStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeContactVerification;
use App\Models\EmployeeCorrectionRequest;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use App\Models\IdCardPrintSnapshot;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Notifications\EmployeeContactCode;
use App\Notifications\EmployeePortalNotification;
use App\Services\Employees\EmployeeContactVerificationService;
use App\Services\Employees\EmployeePortalNotifier;
use App\Services\IdCards\IdCardPrintSnapshotService;
use App\Services\IdCards\IdCardSnapshotComparisonService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function selfServiceFixture(?array $fields = null, string $orientation = 'landscape'): array
{
    $employee = Employee::create(['employee_number' => 'ESS-'.Str::random(8), 'first_name' => 'Test', 'last_name' => 'Employee', 'full_name' => 'Test Employee', 'email' => Str::random(8).'@example.test', 'phone' => '+251911222333', 'date_of_birth' => '1990-02-03', 'gender' => 'female', 'nationality' => 'Ethiopian', 'employment_type' => 'permanent', 'status' => 'active', 'national_id' => '123456784821', 'emergency_contact_name' => 'Contact', 'emergency_contact_phone' => '0911222444']);
    $user = User::factory()->create(['email' => $employee->email, 'status' => 'active']);
    $type = OrganizationType::firstOrCreate(['code' => 'ESS'], ['name_en' => 'ESS']);
    $organization = Organization::create(['organization_type_id' => $type->id, 'code' => Str::random(8), 'name_en' => 'ESS Organization', 'status' => 'active']);
    $assignment = EmployeeAssignment::create(['employee_id' => $employee->id, 'organization_id' => $organization->id, 'assignment_status' => 'active', 'effective_from' => '2020-01-01', 'is_current' => true]);
    $employee->update(['current_assignment_id' => $assignment->id]);
    $officer = User::factory()->create(['status' => 'active']);
    foreach (['id-cards.print', 'cards.view', 'employees.view', 'employees.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $officer->givePermissionTo($permission);
    }
    $template = IdCardTemplate::create(['name' => 'ESS', 'code' => Str::random(8), 'orientation' => $orientation, 'width_mm' => 85.6, 'height_mm' => 54, 'status' => 'active', 'is_default' => true, 'employee_fields' => $fields]);
    $card = IdCard::create(['employee_id' => $employee->id, 'card_number' => 'CARD-'.Str::random(8), 'status' => CardStatus::Active, 'is_current' => true, 'issued_at' => now()->subDay(), 'expires_at' => now()->addYear(), 'public_card_uuid' => (string) Str::uuid(), 'qr_payload' => 'stable-credential', 'token_hash' => hash('sha256', 'stable-secret'), 'token_version' => 1]);

    return compact('employee', 'user', 'officer', 'card', 'template');
}

function confirmSelfServicePrint(array $fixture): IdCardPrintSnapshot
{
    $prints = app(IdCardPrintSnapshotService::class);
    $snapshot = $prints->prepare($fixture['card'], $fixture['officer']);
    $prints->confirm($fixture['card'], $snapshot, $fixture['officer']);

    return $snapshot->fresh();
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

test('safe profile exposes masked identity and rejects authoritative mass assignment', function () {
    $f = selfServiceFixture();
    $this->actingAs($f['user'])->get('/my-portal/profile')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Employee/SelfService')->where('profile.national_id', '**** **** 4821')->missing('profile.national_id_hash')->missing('profile.metadata')->missing('profile.id')->missing('card.public_card_uuid')->missing('card.qr_payload'));
    $this->post('/my-portal/profile', ['address' => 'New address', 'employee_number' => 'HACK', 'role' => 'Super Admin', 'employee_id' => Str::uuid()])->assertSessionHasErrors('profile');
    expect($f['employee']->fresh()->employee_number)->toBe($f['employee']->employee_number)->and($f['employee']->fresh()->address)->toBeNull();
});

test('card independent fields do not change card identity or lifecycle', function (string $field, mixed $value) {
    $f = selfServiceFixture(['name', 'phone']);
    confirmSelfServicePrint($f);
    $original = $f['card']->fresh()->only(['card_number', 'public_card_uuid', 'qr_payload', 'token_hash', 'status']);
    $this->actingAs($f['user'])->post('/my-portal/profile', [$field => $value])->assertSessionHasNoErrors();
    expect($f['card']->fresh()->reprint_required)->toBeFalse()->and($f['card']->fresh()->only(array_keys($original)))->toBe($original);
})->with([['address', 'Updated address'], ['emergency_contact_name', 'New contact'], ['emergency_contact_phone', '0911555666'], ['emergency_contact_relationship', 'Sibling'], ['preferred_language', 'am'], ['notification_preferences', ['email' => false]]]);

test('HR changes mark only printed identity fields for reprint', function (string $field, mixed $value, string $printedKey) {
    $f = selfServiceFixture(['name', 'sex', 'dob', 'nationality', 'employment']);
    confirmSelfServicePrint($f);
    $original = $f['card']->fresh()->only(['card_number', 'public_card_uuid', 'qr_payload', 'token_hash']);
    $f['employee']->update([$field => $value]);
    $card = $f['card']->fresh();
    expect($card->reprint_required)->toBeTrue()->and($card->reprint_reasons)->toContain($printedKey)->and($card->status)->toBe(CardStatus::Active)->and($card->only(array_keys($original)))->toBe($original);
})->with([['full_name', 'New Legal Name', 'name'], ['gender', 'male', 'gender'], ['date_of_birth', '1991-04-05', 'date_of_birth'], ['nationality', 'Other', 'nationality'], ['employment_type', 'contract', 'employment_type']]);

test('verified phone changes are template aware', function (bool $printed) {
    $f = selfServiceFixture($printed ? ['name', 'phone'] : ['name']);
    confirmSelfServicePrint($f);
    $sms = Mockery::mock(SmsGateway::class);
    $sms->shouldReceive('isConfigured')->once()->andReturn(true);
    $code = null;
    $sms->shouldReceive('send')->once()->withArgs(function ($phone, $message) use (&$code) {
        preg_match('/\b(\d{6})\b/', $message, $matches);
        $code = $matches[1];

        return $phone === '+251911555666';
    })->andReturn(true);
    app()->instance(SmsGateway::class, $sms);
    $this->actingAs($f['user'])->post('/my-portal/profile/contact', ['field' => 'phone', 'value' => '+251 911 555666'])->assertSessionHasNoErrors();
    expect($f['employee']->fresh()->phone)->toBe('+251911222333');
    $this->post('/my-portal/profile/contact/confirm', ['field' => 'phone', 'otp' => $code])->assertSessionHasNoErrors();
    expect($f['employee']->fresh()->phone)->toBe('+251911555666')->and($f['card']->fresh()->reprint_required)->toBe($printed);
})->with([true, false]);

test('verified personal email does not reprint or break employee ownership', function () {
    $f = selfServiceFixture(['name']);
    confirmSelfServicePrint($f);
    $this->actingAs($f['user'])->post('/my-portal/profile/contact', ['field' => 'email', 'value' => 'NEW@example.test'])->assertSessionHasNoErrors();
    $code = null;
    Notification::assertSentOnDemand(EmployeeContactCode::class, function ($notification, $channels, $notifiable) use (&$code) {
        $code = $notification->code;

        return $notifiable->routes['mail'] === 'new@example.test';
    });
    $this->post('/my-portal/profile/contact/confirm', ['field' => 'email', 'otp' => $code])->assertSessionHasNoErrors();
    expect($f['employee']->fresh()->email)->toBe('new@example.test')->and($f['user']->fresh()->employee->id)->toBe($f['employee']->id)->and($f['card']->fresh()->reprint_required)->toBeFalse();
    $this->post('/my-portal/profile/contact/confirm', ['field' => 'email', 'otp' => $code])->assertSessionHasErrors('otp');
});

test('a configured email or address really renders and triggers reprint', function (string $field) {
    $f = selfServiceFixture(['name', $field]);
    $snapshot = confirmSelfServicePrint($f);
    expect($snapshot->rendered_fields)->toContain($field);
    $f['employee']->update([$field => $field === 'email' ? 'changed@example.test' : 'New address']);
    expect($f['card']->fresh()->reprint_required)->toBeTrue();
})->with(['email', 'address']);

test('private photo upload triggers reprint only when photo is printed', function () {
    $f = selfServiceFixture();
    confirmSelfServicePrint($f);
    $this->actingAs($f['user'])->post('/my-portal/profile', ['photo' => UploadedFile::fake()->image('photo.jpg', 200, 250)])->assertSessionHasNoErrors();
    expect($f['card']->fresh()->reprint_reasons)->toContain('photo');
    $path = $f['employee']->fresh()->photo_path;
    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
    $this->get('/my-portal/profile/photo')->assertOk();
});

test('snapshot contains only actual rendered safe values and previews cannot clear reprint', function () {
    $f = selfServiceFixture(['name', 'phone']);
    $snapshot = confirmSelfServicePrint($f);
    expect(array_keys($snapshot->comparison_values))->toBe($snapshot->rendered_fields)->and($snapshot->rendered_values)->not->toHaveKeys(['national_id', 'token_hash', 'qr_payload', 'email', 'password']);
    expect(app(IdCardSnapshotComparisonService::class)->compare($f['card'])['has_changes'])->toBeFalse();
    $f['employee']->update(['phone' => '+251922333444']);
    $prepared = app(IdCardPrintSnapshotService::class)->prepare($f['card'], $f['officer']);
    expect($f['card']->fresh()->reprint_required)->toBeTrue()->and($prepared->printed_at)->toBeNull();
    app(IdCardPrintSnapshotService::class)->confirm($f['card'], $prepared, $f['officer']);
    expect($f['card']->fresh()->reprint_required)->toBeFalse()->and(IdCardPrintSnapshot::whereNotNull('printed_at')->count())->toBe(2);
});

test('changes between preparation and confirmation remain reprint required', function () {
    $f = selfServiceFixture(['name', 'phone']);
    $prepared = app(IdCardPrintSnapshotService::class)->prepare($f['card'], $f['officer']);
    $f['employee']->update(['phone' => '+251922333444']);
    app(IdCardPrintSnapshotService::class)->confirm($f['card'], $prepared, $f['officer']);
    expect($prepared->fresh()->comparison_values['phone'])->toBe('+251911222333')->and($f['card']->fresh()->reprint_required)->toBeTrue();
});

test('changing the active template cannot change the physical cards impact rules', function () {
    $f = selfServiceFixture(['name', 'phone']);
    confirmSelfServicePrint($f);
    $f['template']->update(['employee_fields' => ['name']]);
    $f['employee']->update(['phone' => '+251922333444']);
    expect($f['card']->fresh()->reprint_reasons)->toContain('phone');
});

test('legacy cards are explicitly snapshot unavailable without invented changes', function () {
    $f = selfServiceFixture();
    $f['employee']->update(['phone' => '+251922333444']);
    expect($f['card']->fresh()->reprint_required)->toBeFalse()->and(app(IdCardSnapshotComparisonService::class)->compare($f['card'])['snapshot_available'])->toBeFalse();
    $this->actingAs($f['user'])->get('/my-portal/id-card')->assertInertia(fn (Assert $page) => $page->where('card.snapshot_available', false));
});

test('employees cannot read others documents or notifications or confirm prints', function () {
    $f = selfServiceFixture();
    $other = Employee::create(['employee_number' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Employee', 'full_name' => 'Other Employee', 'status' => 'active']);
    $doc = EmployeeDocument::create(['employee_id' => $other->id, 'document_type' => 'private', 'file_path' => 'secret.txt', 'storage_disk' => 'local', 'is_private' => true]);
    Storage::disk('local')->put('secret.txt', 'secret');
    $prepared = app(IdCardPrintSnapshotService::class)->prepare($f['card'], $f['officer']);
    $this->actingAs($f['user'])->get('/my-portal/documents/'.$doc->id.'/download')->assertNotFound();
    $this->post('/my-portal/notifications/'.Str::uuid().'/read')->assertNotFound();
    expect(fn () => app(IdCardPrintSnapshotService::class)->confirm($f['card'], $prepared, $f['user']))->toThrow(AuthorizationException::class);
    $this->post('/my-portal/profile', ['employee_id' => $other->id, 'address' => 'attack'])->assertSessionHasErrors('profile');
    expect($other->fresh()->address)->toBeNull();
});

test('correction requests cannot change master data directly and hide sensitive requested values', function () {
    $f = selfServiceFixture();
    $this->actingAs($f['user'])->post('/my-portal/requests', ['field' => 'national_id', 'requested_value' => '999999991111'])->assertSessionHasNoErrors();
    expect($f['employee']->fresh()->national_id)->toBe('123456784821');
    $this->get('/my-portal/requests')->assertInertia(fn (Assert $page) => $page->has('corrections', 1)->missing('corrections.0.requested_value'));
    expect(DB::table('employee_correction_requests')->value('requested_value'))->not->toBe('999999991111');
});

test('wrong verification attempts are persisted and bounded', function () {
    $f = selfServiceFixture();
    $verification = EmployeeContactVerification::create(['employee_id' => $f['employee']->id, 'user_id' => $f['user']->id, 'field' => 'email', 'value' => 'new@example.test', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
    for ($i = 0; $i < 5; $i++) {
        try {
            app(EmployeeContactVerificationService::class)->confirm($f['user'], 'email', '654321');
        } catch (ValidationException) {
        }
    }
    expect($verification->fresh()->attempts)->toBe(5);
    expect(fn () => app(EmployeeContactVerificationService::class)->confirm($f['user'], 'email', '123456'))->toThrow(ValidationException::class);
});

test('changing account email cannot take ownership of another employee', function () {
    $f = selfServiceFixture();
    $other = Employee::create(['employee_number' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Employee', 'full_name' => 'Other Employee', 'status' => 'active', 'email' => 'other@example.test']);
    $f['user']->update(['email' => $other->email]);
    expect($f['user']->fresh()->employee->id)->toBe($f['employee']->id);
    $f['user']->update(['email' => 'old@example.test']);
    $unlinked = User::factory()->create();
    $unlinked->update(['email' => 'other@example.test']);
    expect($unlinked->fresh()->employee)->toBeNull();
});

test('both locales provide the same portal labels and translated reprint status', function () {
    $en = require lang_path('en/employee-portal.php');
    $am = require lang_path('am/employee-portal.php');
    expect(array_keys($am))->toBe(array_keys($en))->and(array_keys($am['fields']))->toBe(array_keys($en['fields']))->and($am['reprint_required'])->toBe('እንደገና ማተም ያስፈልጋል');
});

test('officer queue and employee notice contain safe reasons', function () {
    $f = selfServiceFixture(['name', 'phone']);
    confirmSelfServicePrint($f);
    $f['employee']->update(['phone' => '+251922333444']);
    $this->actingAs($f['officer'])->get('/id-cards/reprint-required')->assertOk()->assertInertia(fn (Assert $page) => $page->component('IdCards/ReprintQueue')->has('cards.data', 1)->where('cards.data.0.changed_fields.0', 'Phone number')->missing('cards.data.0.token_hash'));
    $this->actingAs($f['user'])->get('/my-portal/id-card')->assertOk()->assertInertia(fn (Assert $page) => $page->where('card.reprint_required', true)->where('card.changed_fields.0', 'Phone number')->missing('card.reprint_reasons'));
    $this->get('/id-cards/reprint-required')->assertForbidden();
});

test('authorized HR correction updates master record and evaluates reprint', function () {
    $f = selfServiceFixture(['name', 'nationality']);
    confirmSelfServicePrint($f);
    $request = EmployeeCorrectionRequest::create(['employee_id' => $f['employee']->id, 'requested_by' => $f['user']->id, 'field' => 'nationality', 'requested_value' => 'Other']);
    $this->actingAs($f['user'])->post(route('employee-corrections.review', $request), ['decision' => 'approved'])->assertForbidden();
    $this->actingAs($f['officer'])->post(route('employee-corrections.review', $request), ['decision' => 'approved'])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('approved')->and($f['employee']->fresh()->nationality)->toBe('Other')->and($f['card']->fresh()->reprint_reasons)->toContain('nationality');
});

test('reprint notifications go to the employee and authorized officer only', function () {
    $f = selfServiceFixture();
    $unrelated = User::factory()->create(['status' => 'active']);
    app(EmployeePortalNotifier::class)->reprint($f['card']);
    Notification::assertSentTo($f['user'], EmployeePortalNotification::class);
    Notification::assertSentTo($f['officer'], EmployeePortalNotification::class);
    Notification::assertNotSentTo($unrelated, EmployeePortalNotification::class);
});

test('snapshot represents portrait fields and ignores unprinted phone and emergency contact', function () {
    $f = selfServiceFixture(null, 'portrait');
    $snapshot = confirmSelfServicePrint($f);
    expect($snapshot->rendered_fields)->toContain('name', 'organization', 'position')->not->toContain('phone', 'emergency_contact_phone', 'nationality');
    $f['employee']->update(['phone' => '+251922333444', 'emergency_contact_name' => 'Changed']);
    expect($f['card']->fresh()->reprint_required)->toBeFalse();
    $f['employee']->currentAssignment->organization->update(['name_en' => 'Renamed organization']);
    expect($f['card']->fresh()->reprint_reasons)->toContain('organization');
});

test('reverting information does not clear a reprint flag and unauthorized printing cannot clear it', function () {
    $f = selfServiceFixture(['name', 'phone']);
    confirmSelfServicePrint($f);
    $f['employee']->update(['phone' => '+251922333444']);
    $f['employee']->update(['phone' => '+251911222333']);
    expect($f['card']->fresh()->reprint_required)->toBeTrue();
    $this->actingAs($f['user'])->post(route('id-cards.prepare-print', $f['card']))->assertForbidden();
    expect($f['card']->fresh()->reprint_required)->toBeTrue();
});

test('initial printing creates a confirmed snapshot and forbids replay', function () {
    $f = selfServiceFixture(['name']);
    $f['card']->update(['status' => CardStatus::PendingPrint]);
    $snapshot = confirmSelfServicePrint($f);
    expect($snapshot->printed_at)->not->toBeNull()->and($f['card']->fresh()->status)->toBe(CardStatus::Printed);
    expect(fn () => app(IdCardPrintSnapshotService::class)->confirm($f['card'], $snapshot, $f['officer']))->toThrow(ValidationException::class);
});

test('a failed SMS delivery cannot change the phone or leave a usable code', function () {
    $f = selfServiceFixture();
    $sms = Mockery::mock(SmsGateway::class);
    $sms->shouldReceive('isConfigured')->once()->andReturn(false);
    $sms->shouldNotReceive('send');
    app()->instance(SmsGateway::class, $sms);
    $this->actingAs($f['user'])->post('/my-portal/profile/contact', ['field' => 'phone', 'value' => '+251922333444'])->assertSessionHasErrors('value');
    expect($f['employee']->fresh()->phone)->toBe('+251911222333')->and(EmployeeContactVerification::where('expires_at', '>', now())->count())->toBe(0);
});

// ── Portal UI contract ──────────────────────────────────────────────────────

test('portal pages send labels in both languages so the browser can switch without a round trip', function () {
    $f = selfServiceFixture(['name', 'phone']);
    $this->actingAs($f['user'])->get('/my-portal/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('labels.en.reprint_required', 'Reprint Required')
        ->where('labels.am.reprint_required', 'እንደገና ማተም ያስፈልጋል')
        ->where('labels.en.section_intro.profile', trans('employee-portal.section_intro.profile', [], 'en'))
        ->has('correction_fields', 7));
    $this->get('/my-portal')->assertOk()->assertInertia(fn (Assert $page) => $page->where('portal_labels.am.profile', trans('employee-portal.profile', [], 'am')));
});

test('reprint reasons reach the employee as field keys and never as internal reason codes', function () {
    $f = selfServiceFixture(['name', 'phone']);
    confirmSelfServicePrint($f);
    $f['employee']->update(['phone' => '+251922333444']);
    $this->actingAs($f['user'])->get('/my-portal/id-card')->assertInertia(fn (Assert $page) => $page
        ->where('card.changed_field_keys', ['phone'])
        ->missing('card.reprint_reasons'));
    $this->get('/my-portal')->assertInertia(fn (Assert $page) => $page->where('id_card.reprint_required', true));
});

test('placement names and employment type are sent in both languages', function () {
    $f = selfServiceFixture();
    $f['employee']->currentAssignment->organization->update(['name_am' => 'የሙከራ ተቋም']);
    $this->actingAs($f['user'])->get('/my-portal/employment')->assertInertia(fn (Assert $page) => $page
        ->where('profile.organization', 'ESS Organization')
        ->where('profile.organization_am', 'የሙከራ ተቋም')
        ->where('profile.employment_type', EmploymentType::Permanent->label('en'))
        ->where('profile.employment_type_am', EmploymentType::Permanent->label('am')));
});

test('re-saving the whole form with an unchanged printed field does not mark a reprint', function () {
    $f = selfServiceFixture(['name', 'address']);
    $f['employee']->update(['address' => 'Bole, Addis Ababa']);
    confirmSelfServicePrint($f);

    // The profile form posts every editable field at once.
    $this->actingAs($f['user'])->post('/my-portal/profile', [
        'address' => 'Bole, Addis Ababa',
        'emergency_contact_name' => 'New contact',
        'emergency_contact_phone' => '0911222444',
        'emergency_contact_relationship' => 'Sibling',
        'preferred_language' => 'am',
        'notification_preferences' => ['email' => 0],
    ])->assertSessionHasNoErrors();

    expect($f['card']->fresh()->reprint_required)->toBeFalse();
});

test('the self-service page holds no hardcoded English chrome', function () {
    foreach (['Pages/Employee/SelfService.tsx', 'Components/employees/portal/ProfileSection.tsx', 'Components/employees/portal/ContactChange.tsx'] as $file) {
        $source = file_get_contents(resource_path('js/'.$file));
        foreach (['Save changes', 'Reprint Required', 'Request correction', 'Verification code', 'Personal information', "'en-US'", 'bg-blue-700'] as $literal) {
            expect($source)->not->toContain($literal);
        }
    }
});
