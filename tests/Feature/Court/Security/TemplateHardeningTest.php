<?php

declare(strict_types=1);

/*
 * Letter and decision template bodies are HTML that later becomes letters and
 * decision PDFs, so both are sanitised when they are saved. Placeholders are
 * plain text and survive.
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Modules\Court\Domain\Models\DecisionTemplate;
use Modules\Court\Domain\Models\LetterTemplate;

const TEMPLATE_HARDENING_BODY = '<p>Dear {{ applicant_name }}, case {{case_number}}.</p>'
    .'<script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">link</a>';

function templateBodyIsClean(string $body): void
{
    expect($body)->toContain('{{ applicant_name }}')
        ->and($body)->toContain('{{case_number}}')
        ->and($body)->not->toContain('<script')
        ->and($body)->not->toContain('onerror')
        ->and($body)->not->toContain('javascript:');
}

test('a letter template body is sanitised on create and on update', function (): void {
    $admin = jwStaff(['letters.templet.view', 'letters.templet.create', 'letters.templet.update']);

    $this->actingAs($admin)
        ->post(route('court.letter-templates.store'), ['title' => 'Summons', 'body' => TEMPLATE_HARDENING_BODY])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.letter-templates.index'));

    $template = LetterTemplate::query()->where('title', 'Summons')->sole();
    templateBodyIsClean((string) $template->body);

    $this->actingAs($admin)
        ->patch(route('court.letter-templates.update', $template), ['title' => 'Summons', 'body' => TEMPLATE_HARDENING_BODY.'<iframe src="https://example.test"></iframe>'])
        ->assertSessionHasNoErrors();

    $body = (string) $template->fresh()->body;
    templateBodyIsClean($body);
    expect($body)->not->toContain('<iframe');
});

test('a decision template body is sanitised on create and on update', function (): void {
    $admin = jwStaff(['decision.templet.view', 'decision.templet.create', 'decision.templet.update']);

    $this->actingAs($admin)
        ->post(route('court.decision-templates.store'), ['title' => 'Judgment', 'body' => TEMPLATE_HARDENING_BODY])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.decision-templates.index'));

    $template = DecisionTemplate::query()->where('title', 'Judgment')->sole();
    templateBodyIsClean((string) $template->body);

    $this->actingAs($admin)
        ->patch(route('court.decision-templates.update', $template), ['title' => 'Judgment', 'body' => TEMPLATE_HARDENING_BODY])
        ->assertSessionHasNoErrors();

    templateBodyIsClean((string) $template->fresh()->body);
});
