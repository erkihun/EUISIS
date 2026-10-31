<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Support\DecisionPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function decisionWithPanelJudge(User $judge, string $status = 'published', bool $approved = true): Decision
{
    $caseType = CaseType::query()->create(['name' => 'PDF signature test']);
    $courtCase = CourtCase::query()->create([
        'case_number' => 'PDF-'.Str::upper(Str::random(10)),
        'title' => 'PDF signature case',
        'case_type_id' => $caseType->id,
        'filing_date' => now()->toDateString(),
    ]);

    return Decision::query()->create([
        'court_case_id' => $courtCase->id,
        'case_number' => $courtCase->case_number,
        'decision_date' => now()->toDateString(),
        'panel_judges' => [[
            'order' => 1,
            'admin_user_id' => $judge->id,
            'admin_user_name' => $judge->name,
            'vote' => null,
        ]],
        'name' => 'PDF signature ruling',
        'decision_content' => '<p>Decision content.</p>',
        'status' => $status,
        'approved_at' => $approved ? now() : null,
        'approved_by' => $approved ? $judge->id : null,
    ]);
}

test('approved published decision embeds an available panel judge signature', function (): void {
    Storage::fake('court_public');
    $signaturePath = 'signatures/judge.png';
    Storage::disk('court_public')->put(
        $signaturePath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
    );
    $judge = User::factory()->create(['signature_path' => $signaturePath]);
    $decision = decisionWithPanelJudge($judge);

    $pdf = DecisionPdf::render($decision)->output();

    expect(substr_count($pdf, '/Subtype /Image'))->toBeGreaterThanOrEqual(1);
});

test('published decision embeds judge signatures before approval', function (): void {
    Storage::fake('court_public');
    $signaturePath = 'signatures/judge.png';
    Storage::disk('court_public')->put(
        $signaturePath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
    );
    $judge = User::factory()->create(['signature_path' => $signaturePath]);
    $decision = decisionWithPanelJudge($judge, approved: false);

    $pdf = DecisionPdf::render($decision)->output();

    expect(substr_count($pdf, '/Subtype /Image'))->toBeGreaterThanOrEqual(1);
});

test('draft decision does not embed judge signatures', function (): void {
    Storage::fake('court_public');
    $signaturePath = 'signatures/judge.png';
    Storage::disk('court_public')->put(
        $signaturePath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
    );
    $judge = User::factory()->create(['signature_path' => $signaturePath]);
    $decision = decisionWithPanelJudge($judge, status: 'draft', approved: false);

    $pdf = DecisionPdf::render($decision)->output();

    expect(substr_count($pdf, '/Subtype /Image'))->toBe(0);
});

test('missing signature file leaves the signature line empty', function (): void {
    Storage::fake('court_public');
    $judge = User::factory()->create([
        'signature_path' => 'signatures/missing.png',
    ]);
    $decision = decisionWithPanelJudge($judge);

    $pdf = DecisionPdf::render($decision)->output();

    expect(substr_count($pdf, '/Subtype /Image'))->toBe(0);
});

test('pdf displays the decision author first with the matching signature without changing storage', function (): void {
    $firstStoredJudge = User::factory()->create(['name' => 'First Stored Judge']);
    $author = User::factory()->create(['name' => 'Decision Author']);
    $viewer = User::factory()->create(['name' => 'Different Viewer']);
    $thirdJudge = User::factory()->create(['name' => 'Third Judge']);
    $decision = decisionWithPanelJudge($firstStoredJudge);
    $storedPanel = [
        ['order' => 1, 'admin_user_id' => $firstStoredJudge->id, 'admin_user_name' => $firstStoredJudge->name],
        ['order' => 2, 'admin_user_id' => $author->id, 'admin_user_name' => $author->name],
        ['order' => 3, 'admin_user_id' => $thirdJudge->id, 'admin_user_name' => $thirdJudge->name],
    ];
    $decision->update([
        'panel_judges' => $storedPanel,
        'reviewing_admin_user_id' => $author->id,
        'reviewing_admin_user_name' => $author->name,
    ]);

    $this->actingAs($viewer);
    $displayPanel = $decision->panelJudgesAuthorFirst();
    $html = view('court::pdf.decision-output', [
        'decision' => $decision,
        'template' => null,
        'body' => '<p>Decision content.</p>',
        'sealPath' => null,
        'displayPanel' => $displayPanel,
        'signaturePaths' => ['author.png', 'first.png', 'third.png'],
    ])->render();

    expect(strpos($html, 'Decision Author'))->toBeLessThan(strpos($html, 'First Stored Judge'))
        ->and(strpos($html, 'author.png'))->toBeLessThan(strpos($html, 'first.png'))
        ->and(DecisionPdf::fillPlaceholders('{{judge_one}}', $decision))->toBe('Decision Author')
        ->and($decision->fresh()->panel_judges)->toBe($storedPanel);
});
