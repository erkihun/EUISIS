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
 * The front face carries identity fields only. Organization and position stay
 * in the database (and on the back face) but must not appear on the front.
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
    'phone number' => ['ስልክ ቁጥር', '+251911223344', 'Phone Number', '+251911223344'],
    'id number' => ['መታወቂያ ቁጥር', 'CARD-FRONT-1', 'ID Number', 'CARD-FRONT-1'],
]);

it('never joins the two languages with a pipe separator', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);

    foreach (['ስም | Name', 'ጾታ | Sex', 'የትውልድ ቀን | Date of Birth', 'ዜግነት | Nationality',
        'የቅጥር ሁኔታ | Employment Status', 'ስልክ ቁጥር | Phone Number', 'መታወቂያ ቁጥር | ID Number'] as $old) {
        expect($svg)->not->toContain($old);
    }
})->with(['landscape', 'portrait']);

it('omits position code and organization name from both front layouts', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);

    expect($svg)->not->toContain('POS-FRONT-9')
        ->and($svg)->not->toContain('Front Organization')
        ->and($svg)->not->toContain('የፊት ድርጅት');
})->with(['landscape', 'portrait']);

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

    // Body fields sit in two columns over three rows.
    $body = [];
    foreach (['ስም', 'ጾታ', 'የትውልድ ቀን', 'ዜግነት', 'የቅጥር ሁኔታ'] as $label) {
        $body[] = $find($label);
    }
    expect(array_filter($body))->toHaveCount(5)
        ->and(array_unique(array_column($body, 'x')))->toHaveCount(2);

    // ID number sits lower-left, phone number to its right, both below the body.
    $idNumber = $find('መታወቂያ ቁጥር');
    $phone = $find('ስልክ ቁጥር');
    $lastBodyRow = max(array_column($body, 'y'));
    expect($idNumber['y'])->toBeGreaterThan($lastBodyRow)
        ->and($phone['y'])->toBe($idNumber['y'])
        ->and($phone['x'])->toBeGreaterThan($idNumber['x']);

    // Footer is centred beneath everything.
    $footer = null;
    foreach ($matches as $match) {
        if (str_contains($match[3], 'Authorized') || str_contains($match[3], 'መታወቂያ ካርድ') || str_contains($match[3], 'ይፋዊ')) {
            $footer = ['x' => (int) $match[1], 'y' => (int) $match[2]];
        }
    }
    expect($footer)->not->toBeNull()
        ->and($footer['y'])->toBeGreaterThan($idNumber['y'])
        ->and($footer['x'])->toBe(428); // half of the 856-wide canvas
});

it('falls back to a dash for missing field values', function (): void {
    $card = frontCard();
    $card->employee->forceFill(['nationality' => null, 'phone' => null, 'date_of_birth' => null])->save();

    $svg = renderFrontSvg($card);

    // Labels stay put so the grid keeps its shape; only the values degrade.
    expect($svg)->toContain('ዜግነት')->and($svg)->toContain('ስልክ ቁጥር')
        ->and(substr_count($svg, '>-<'))->toBe(6); // Both language rows keep their own value.
});

it('renders amharic labels without mangling the encoding', function (): void {
    $svg = renderFrontSvg(frontCard());

    expect($svg)->toContain('ጾታ')->and($svg)->toContain('የትውልድ ቀን')->and($svg)->toContain('መታወቂያ ቁጥር')
        ->and(mb_check_encoding($svg, 'UTF-8'))->toBeTrue()
        ->and($svg)->toContain('Noto Sans Ethiopic');
});

it('shows every bilingual label in full, never clipped', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);

    // A wrapped label splits at the separator, so both halves must survive intact.
    foreach (['ጾታ', 'Sex', 'የትውልድ ቀን', 'Date of Birth', 'ዜግነት', 'Nationality',
        'የቅጥር ሁኔታ', 'Employment Status', 'ስልክ ቁጥር', 'Phone Number', 'መታወቂያ ቁጥር', 'ID Number'] as $label) {
        expect($svg)->toContain($label);
    }
    expect($svg)->not->toContain('…');
})->with(['landscape', 'portrait']);

it('applies the configured header and footer colours to the front face', function (): void {
    IdCardTemplate::query()->create([
        'name' => 'Header styled', 'code' => 'header-styled', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'text_style_config' => ['front' => [
            'header' => ['color' => '#FF7700', 'font_size' => '12px', 'font_weight' => '800'],
            'footer' => ['color' => '#00FF77', 'font_size' => '8px', 'font_weight' => '400'],
        ]],
    ]);

    $svg = renderFrontSvg(frontCard());

    expect($svg)->toContain('#FF7700')->and($svg)->toContain('#00FF77');
});

it('prints a value on each language row even when both read the same', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);

    // Phone repeats in no other block, so its count is the field's own output.
    expect(substr_count($svg, '>+251911223344<'))->toBe(2);

    // Both labels still appear, so the row stays bilingual.
    expect($svg)->toContain('ስልክ ቁጥር')->and($svg)->toContain('Phone Number')
        ->and($svg)->toContain('መታወቂያ ቁጥር')->and($svg)->toContain('ID Number');

    // Values that genuinely differ per language are still printed twice.
    expect($svg)->toContain('ወንድ')->and($svg)->toContain('Male');
})->with(['landscape', 'portrait']);

it('takes structure from the reference without copying its artwork or sample data', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);

    // Sample values and decorative colours from the reference must never appear.
    foreach (['Abebe Kebede', 'አበበ ከበደ', '0000000000', '+2510000000000', 'Emp.Status'] as $sample) {
        expect($svg)->not->toContain($sample);
    }

    // Real card data is what renders instead.
    expect($svg)->toContain('CARD-FRONT-1')->and($svg)->toContain('+251911223344');
})->with(['landscape', 'portrait']);

it('keeps the whole front face inside the card bounds', function (string $orientation): void {
    $svg = renderFrontSvg(frontCard(), $orientation);
    [$width, $height] = $orientation === 'portrait' ? [540, 856] : [856, 540];

    preg_match_all('/<text x="(-?\d+)" y="(-?\d+)"/', $svg, $matches, PREG_SET_ORDER);
    expect(max(array_map(fn (array $m): int => (int) $m[1], $matches)))->toBeLessThan($width)
        ->and(max(array_map(fn (array $m): int => (int) $m[2], $matches)))->toBeLessThan($height);
})->with(['landscape', 'portrait']);
