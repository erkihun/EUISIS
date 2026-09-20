<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use Spatie\Permission\Models\Permission;

/**
 * Landscape keeps the detailed identity grid. Portrait uses the compact
 * organization, photo, employee name and position arrangement.
 */
function frontCard(): IdCard
{
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-FRONT-1', 'full_name' => 'Front Employee',
        'first_name' => 'Front', 'last_name' => 'Employee', 'status' => EmployeeStatus::Active,
        'gender' => 'male', 'date_of_birth' => '1990-03-15',
        'nationality' => 'Ethiopian', 'phone' => '+251911223344',
        'employment_type' => 'permanent',
    ]);

    $type = OrganizationType::query()->create(['code' => 'FRONT-T', 'name_en' => 'Front type']);
    $organization = Organization::query()->create([
        'code' => 'FRONT-ORG', 'name_en' => 'Front Organization', 'name_am' => 'የፊት ድርጅት',
        'organization_type_id' => $type->id, 'status' => OrganizationStatus::Active,
    ]);
    $position = Position::query()->create([
        'organization_id' => $organization->id, 'title_en' => 'Front Officer', 'title_am' => 'የፊት ሹም',
        'code' => 'FRONT-POS', 'job_position_code' => 'POS-FRONT-9',
    ]);
    $version = HierarchyVersion::query()->create(['version_name' => 'front-test', 'status' => HierarchyVersionStatus::Published]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'position_id' => $position->id,
        'hierarchy_version_id' => $version->id, 'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'CARD-FRONT-1', 'status' => CardStatus::Active,
        'token_hash' => hash('sha256', 'front-token'), 'issued_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        'token_version' => 3, 'qr_payload' => 'front-payload', 'is_current' => true,
    ]);
    app(CardQrPayloadService::class)->ensurePublicReference($card);

    return $card->refresh();
}

function renderFrontSvg(IdCard $card, string $orientation = 'landscape'): string
{
    return app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh(), $orientation),
    );
}

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    foreach (['cards.view', 'id-cards.previewSvg'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->admin->givePermissionTo(['cards.view', 'id-cards.previewSvg']);
    $this->actingAs($this->admin);
    app()->setLocale('en');
});

it('shows an amharic row above an english row for every field', function (string $amLabel, ?string $amValue, string $enLabel, ?string $enValue): void {
    $svg = renderFrontSvg(frontCard());

    preg_match_all('/<text x="(\d+)" y="(\d+)"[^>]*>([^<]*)</', $svg, $matches, PREG_SET_ORDER);
    $rowOf = function (string $needle) use ($matches): ?int {
        foreach ($matches as $match) {
            if (trim($match[3]) === $needle) {
                return (int) $match[2];
            }
        }

        return null;
    };

    $amRow = $rowOf($amLabel);
    $enRow = $rowOf($enLabel);
    expect($amRow)->not->toBeNull()->and($enRow)->not->toBeNull()
        ->and($amRow)->toBeLessThan($enRow); // Amharic first, English beneath it

    foreach ([$amValue, $enValue] as $value) {
        if ($value !== null) {
            expect($svg)->toContain($value);
        }
    }
})->with([
    'name' => ['ስም', 'Front Employee', 'Name', 'Front Employee'],
    'sex' => ['ጾታ', 'ወንድ', 'Sex', 'Male'],
    'date of birth' => ['የትውልድ ቀን', 'መጋቢት 6, 1982', 'Date of Birth', '15 Mar 1990'],
    'nationality' => ['ዜግነት', 'ኢትዮጵያዊ', 'Nationality', 'Ethiopian'],
    'employment status' => ['የቅጥር ሁኔታ', 'ቋሚ', 'Employment Status', 'Permanent'],
]);

it('joins the labels and writes the value once when both languages share it', function (string $joinedLabel, string $value): void {
    $svg = renderFrontSvg(frontCard());

    // An ID or phone number reads the same in either language, so the row is
    // collapsed: one joined label, one value, no duplicated line.
    expect($svg)->toContain($joinedLabel)
        ->and(substr_count($svg, '>'.$joinedLabel.'<'))->toBe(1)
        ->and(substr_count($svg, '>'.$value.'<'))->toBe(1);
})->with([
    'phone number' => ['ስ.ቁ / Phone No.', '+251911223344'],
    'id number' => ['መ.ቁ / ID.No', 'EMP-FRONT-1'],
]);

it('abbreviates the id and phone labels rather than spelling them out', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);

    foreach (['መታወቂያ ቁጥር', 'ID Number', 'ስልክ ቁጥር', 'Phone Number'] as $spelledOut) {
        expect($svg)->not->toContain($spelledOut);
    }
})->with(['landscape', 'portrait']);

it('never joins the two languages with a pipe separator', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);

    foreach (['ስም | Name', 'ጾታ | Sex', 'የትውልድ ቀን | Date of Birth', 'ዜግነት | Nationality',
        'የቅጥር ሁኔታ | Employment Status', 'ስ.ቁ | Phone No.', 'መ.ቁ | ID.No'] as $old) {
        expect($svg)->not->toContain($old);
    }
})->with(['landscape', 'portrait']);

it('keeps organization and position off the landscape front', function (): void {
    $svg = renderFrontSvg(frontCard(), 'landscape');

    expect($svg)->not->toContain('POS-FRONT-9')
        ->and($svg)->not->toContain('Front Organization')
        ->and($svg)->not->toContain('የፊት ድርጅት');
});

it('prints the full bilingual employer organization and employee position on the portrait front', function (): void {
    $card = frontCard();
    $card->employee->currentAssignment->organization->update([
        'name_en' => 'Addis Ababa Comprehensive Public Service Human Resource Development Directorate',
    ]);
    IdCardTemplate::query()->create([
        'name' => 'Employer only', 'code' => 'employer-only', 'orientation' => 'portrait',
        'status' => 'active', 'is_default' => true,
        'header_config' => [
            'city_name_en' => 'Repeated City', 'city_name_am' => 'የተደገመ ከተማ',
            'bureau_name_en' => 'Repeated Bureau', 'bureau_name_am' => 'የተደገመ ቢሮ',
        ],
    ]);
    $svg = renderFrontSvg($card, 'portrait');

    expect($svg)->toContain('Comprehensive Public Service')
        ->and($svg)->toContain('Resource Development Directorate')
        ->and($svg)->toContain('የፊት ድርጅት')
        ->and($svg)->toContain('Front Officer')
        ->and($svg)->toContain('የፊት ሹም')
        ->and($svg)->not->toContain('Repeated City')
        ->and($svg)->not->toContain('Repeated Bureau')
        ->and($svg)->not->toContain('POS-FRONT-9');
});

it('keeps organization and position data in the database', function (): void {
    $assignment = frontCard()->employee->currentAssignment;

    expect($assignment->organization->name_en)->toBe('Front Organization')
        ->and($assignment->position->job_position_code)->toBe('POS-FRONT-9');
});

it('formats dates per locale and never as a raw ISO string', function (): void {
    $card = frontCard();

    app()->setLocale('en');
    expect(renderFrontSvg($card))->toContain('15 Mar 1990')->and(renderFrontSvg($card))->not->toContain('1990-03-15');

    app()->setLocale('am');
    expect(renderFrontSvg($card))->toContain('መጋቢት 6, 1982')->and(renderFrontSvg($card))->not->toContain('1990-03-15');
});

it('serves preview and export from the same front layout', function (): void {
    $card = frontCard();
    $rendered = renderFrontSvg($card);

    $response = $this->get(route('id-cards.preview.svg.front', $card))->assertOk();
    expect($response->getContent())->toBe($rendered);
});

it('does not rotate the QR reference when front fields change', function (): void {
    $card = frontCard();
    $before = $card->getRawOriginal();
    $qrBefore = app(IdCardRenderDataFactory::class)->make($card)->qrVerificationUrl;

    $card->employee->forceFill([
        'nationality' => 'Kenyan', 'phone' => '+251900000000', 'date_of_birth' => '1991-01-01',
    ])->save();
    renderFrontSvg($card);

    expect($card->refresh()->getRawOriginal())->toBe($before)
        ->and(app(IdCardRenderDataFactory::class)->make($card)->qrVerificationUrl)->toBe($qrBefore);
});

it('lays the identity fields out in two columns above an emphasised bottom band', function (): void {
    $svg = renderFrontSvg(frontCard());

    preg_match_all('/<text x="(\d+)" y="(\d+)"[^>]*>([^<]*)</', $svg, $matches, PREG_SET_ORDER);
    $find = function (string $needle) use ($matches): ?array {
        foreach ($matches as $match) {
            if (trim($match[3]) === $needle) {
                return ['x' => (int) $match[1], 'y' => (int) $match[2]];
            }
        }

        return null;
    };

    // Body fields sit in two columns; phone is one of them, under nationality.
    $body = [];
    foreach (['ስም', 'ጾታ', 'የትውልድ ቀን', 'ዜግነት', 'የቅጥር ሁኔታ', 'ስ.ቁ / Phone No.'] as $label) {
        $body[] = $find($label);
    }
    expect(array_filter($body))->toHaveCount(6)
        ->and(array_unique(array_column($body, 'x')))->toHaveCount(2);

    // Phone follows nationality in the same column.
    $nationality = $find('ዜግነት');
    $phone = $find('ስ.ቁ / Phone No.');
    expect($phone['x'])->toBe($nationality['x'])
        ->and($phone['y'])->toBeGreaterThan($nationality['y']);

    // Only the ID number is emphasised, below every body field.
    $idNumber = $find('መ.ቁ / ID.No');
    expect($idNumber['y'])->toBeGreaterThan(max(array_column($body, 'y')));

    // The front carries no authorisation footer; the dates close the face.
    foreach ($matches as $match) {
        expect($match[3])->not->toContain('Authorized');
    }
});

it('falls back to a dash for missing field values', function (): void {
    $card = frontCard();
    $card->employee->forceFill(['nationality' => null, 'phone' => null, 'date_of_birth' => null])->save();

    $svg = renderFrontSvg($card);

    // Labels stay put so the grid keeps its shape; only the values degrade.
    // Nationality and date of birth print a dash on each language row; the
    // phone row joins its labels, so its single missing value dashes once.
    expect($svg)->toContain('ዜግነት')->and($svg)->toContain('ስ.ቁ')
        ->and(substr_count($svg, '>-<'))->toBe(5);
});

it('renders amharic labels without mangling the encoding', function (): void {
    $svg = renderFrontSvg(frontCard());

    expect($svg)->toContain('ጾታ')->and($svg)->toContain('የትውልድ ቀን')->and($svg)->toContain('መ.ቁ')
        ->and(mb_check_encoding($svg, 'UTF-8'))->toBeTrue()
        ->and($svg)->toContain('Noto Sans Ethiopic');
});

it('shows every bilingual label in full on the landscape detail layout', function (): void {
    $svg = renderFrontSvg(frontCard(), 'landscape');

    // A wrapped label splits at the separator, so both halves must survive intact.
    foreach (['ጾታ', 'Sex', 'የትውልድ ቀን', 'Date of Birth', 'ዜግነት', 'Nationality',
        'የቅጥር ሁኔታ', 'Employment Status', 'ስ.ቁ / Phone No.', 'መ.ቁ / ID.No'] as $label) {
        expect($svg)->toContain($label);
    }
    expect($svg)->not->toContain('…');
});

it('applies the configured header and label colours to the front face', function (): void {
    IdCardTemplate::query()->create([
        'name' => 'Header styled', 'code' => 'header-styled', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'text_style_config' => ['front' => [
            'header' => ['color' => '#FF7700', 'font_size' => '12px', 'font_weight' => '800'],
            'label' => ['color' => '#00FF77', 'font_size' => '8px', 'font_weight' => '400'],
        ]],
    ]);

    $svg = renderFrontSvg(frontCard());

    expect($svg)->toContain('#FF7700')->and($svg)->toContain('#00FF77');
});

it('prints a value on each language row when the two languages differ', function (): void {
    $svg = renderFrontSvg(frontCard(), 'landscape');

    // Values that genuinely differ per language are printed twice.
    expect($svg)->toContain('ወንድ')->and($svg)->toContain('Male');

    // The ID and phone rows are the exception: their labels join on one line
    // and the value is written once, because it reads the same either way.
    expect(substr_count($svg, '>+251911223344<'))->toBe(1)
        ->and(substr_count($svg, '>EMP-FRONT-1<'))->toBe(1)
        ->and($svg)->toContain('ስ.ቁ / Phone No.')
        ->and($svg)->toContain('መ.ቁ / ID.No');
});

it('takes structure from the reference without copying its artwork or sample data', function (): void {
    $svg = renderFrontSvg(frontCard(), 'landscape');

    // Sample values and decorative colours from the reference must never appear.
    foreach (['Abebe Kebede', 'አበበ ከበደ', '0000000000', '+2510000000000', 'Emp.Status'] as $sample) {
        expect($svg)->not->toContain($sample);
    }

    // Real card data is what renders instead.
    expect($svg)->toContain('EMP-FRONT-1')->and($svg)->toContain('+251911223344');
});

it('omits the detail fields from the portrait front', function (): void {
    $card = frontCard();
    $data = app(IdCardRenderDataFactory::class)->make($card, 'portrait');
    $svg = renderFrontSvg($card, 'portrait');

    expect($svg)->toContain('Front Employee')
        ->and($svg)->toContain('Front Officer')
        ->and($svg)->toContain('EMP-FRONT-1')
        ->and($svg)->not->toContain('+251911223344')
        ->and($svg)->not->toContain('Date of Birth')
        ->and($svg)->not->toContain('Nationality')
        ->and($svg)->not->toContain('Employment Status')
        ->and($svg)->not->toContain($data->issueDateFormatted)
        ->and($svg)->not->toContain($data->expiryDateFormatted);
});

it('keeps the whole front face inside the card bounds', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);
    [$width, $height] = $orientation === 'portrait' ? [540, 856] : [856, 540];

    preg_match_all('/<text x="(-?\d+)" y="(-?\d+)"/', $svg, $matches, PREG_SET_ORDER);
    expect(max(array_map(fn (array $m): int => (int) $m[1], $matches)))->toBeLessThan($width)
        ->and(max(array_map(fn (array $m): int => (int) $m[2], $matches)))->toBeLessThan($height);
})->with(['landscape', 'portrait']);

it('prints nationality in each row language however it was typed', function (string $stored): void {
    $card = frontCard();
    $card->employee->forceFill(['nationality' => $stored])->save();

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    [, $valueAm, , $valueEn] = collect($data->bilingualFields)
        ->first(fn (array $field): bool => $field[4] === 'nationality');

    // A record holding the Amharic spelling must still print English on the
    // English row, and the reverse.
    expect($valueAm)->toBe('ኢትዮጵያዊ')->and($valueEn)->toBe('Ethiopian');
})->with(['Ethiopian', 'ethiopian', 'ኢትዮጵያዊ']);
