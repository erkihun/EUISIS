<?php

declare(strict_types=1);

use App\Support\EthiopianCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Actions\ApproveLetter;
use Modules\Letters\Application\Actions\DispatchLetter;
use Modules\Letters\Application\Actions\PublishTemplate;
use Modules\Letters\Application\Actions\SaveNumberingRule;
use Modules\Letters\Application\Actions\SaveTemplate;
use Modules\Letters\Application\Actions\SubmitForApproval;
use Modules\Letters\Application\Services\LetterNumbering;
use Modules\Letters\Application\Services\TemplateRenderer;
use Modules\Letters\Contracts\Data\IssueLetterRequest;
use Modules\Letters\Contracts\LetterIssuer;
use Modules\Letters\Contracts\LetterLinks;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterNumberingRule;
use Modules\Letters\Domain\Models\LetterRegistration;
use Tests\Support\LettersScenario;

/*
 * The module's building blocks (ADR 0037): numbering per institution and
 * direction, template rendering, publishing rules and the public contract.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = LettersScenario::build();
});

it('numbers letters per institution and direction, restarting every Ethiopian year by default', function (): void {
    $numbering = app(LetterNumbering::class);
    $a = $this->s->incoming('A');
    $b = $this->s->incoming('A');
    $other = $this->s->incoming('B');

    $ec = EthiopianCalendar::toEthiopian(CarbonImmutable::now())['year'];
    expect($a->referenceNumber())->toBe("LT-A/0001/{$ec}")
        ->and($b->referenceNumber())->toBe("LT-A/0002/{$ec}")
        ->and($other->referenceNumber())->toBe("LT-B/0001/{$ec}");

    $rule = LetterNumberingRule::fallback(LetterDirection::Outgoing);
    expect($numbering->preview($rule, $this->s->org['A']->id))->toBe("LT-A/0001/{$ec}");
});

it('keeps the incoming and outgoing registers as separate books, so number 1 exists in each', function (): void {
    $signatory = $this->s->signatory('A');
    $incoming = $this->s->incoming('A');
    $letter = $this->s->outgoing('A', 'B', $signatory);
    app(SubmitForApproval::class)->execute($letter, $signatory);
    app(ApproveLetter::class)->execute($letter->fresh(), $signatory);
    $letter = app(DispatchLetter::class)->execute($letter->fresh(), $signatory);

    expect($incoming->referenceNumber())->toBe($letter->referenceNumber())
        ->and(LetterRegistration::query()->where('organization_id', $this->s->org['A']->id)->whereNotNull('registry_number')->count())->toBe(2);
});

it('applies the institution rule over the platform rule over the built-in default', function (): void {
    $admin = $this->s->administrator('A');
    $steward = $this->s->user(['letters.numbering.manage'], [], true);
    app(SaveNumberingRule::class)->execute(['organization_id' => null, 'direction' => 'incoming', 'language' => 'am', 'pattern' => '{DIR}-{SEQ}/{GC_YEAR}', 'reset_policy' => 'gregorian_year', 'padding' => 3, 'direction_code' => 'IN'], $steward);
    app(SaveNumberingRule::class)->execute(['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'language' => 'am', 'pattern' => 'ን/ቢ/{SEQ}', 'reset_policy' => 'never', 'padding' => 2], $admin);

    $a = $this->s->incoming('A');
    $b = $this->s->incoming('B');
    $year = now()->format('Y');

    expect($a->referenceNumber())->toBe('ን/ቢ/01')
        ->and($b->referenceNumber())->toBe("IN-001/{$year}")
        ->and(LetterRegistration::query()->where('letter_id', $a->id)->value('period_key'))->toBe('all');
});

it('numbers Amharic and English letters of one institution separately, and lets a rule name one language', function (): void {
    $admin = $this->s->administrator('A');
    $ec = EthiopianCalendar::toEthiopian(CarbonImmutable::now())['year'];

    $am1 = $this->s->incoming('A', $admin, ['language' => 'am']);
    $en1 = $this->s->incoming('A', $admin, ['language' => 'en']);
    $am2 = $this->s->incoming('A', $admin, ['language' => 'am']);
    $year = now()->format('Y');
    expect($am1->referenceNumber())->toBe("LT-A/0001/{$ec}")
        ->and($en1->referenceNumber())->toBe("LT-A/EN/0001/{$year}")
        ->and($am2->referenceNumber())->toBe("LT-A/0002/{$ec}");

    // One pattern for both languages must carry {LANG}.
    expect(fn () => app(SaveNumberingRule::class)->execute(['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'pattern' => '{ORG}/{SEQ}'], $admin))->toThrow(ValidationException::class);

    // An English-only rule applies to English letters; Amharic letters keep the institution's rule for both languages.
    app(SaveNumberingRule::class)->execute(['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'language' => null, 'pattern' => '{ORG}/{LANG}/{SEQ}', 'padding' => 2], $admin);
    app(SaveNumberingRule::class)->execute(['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'language' => 'en', 'pattern' => 'IN-{LANG}-{SEQ}/{GC_YEAR}', 'language_code' => 'ENG', 'padding' => 3, 'reset_policy' => 'gregorian_year'], $admin);
    $am3 = $this->s->incoming('A', $admin, ['language' => 'am']);
    $en2 = $this->s->incoming('A', $admin, ['language' => 'en']);

    expect($am3->referenceNumber())->toBe('LT-A/AM/03')
        ->and($en2->referenceNumber())->toBe("IN-ENG-002/{$year}");

    $numbering = app(LetterNumbering::class);
    expect($numbering->ruleFor($this->s->org['A']->id, LetterDirection::Incoming, 'en')->language)->toBe('en')
        ->and($numbering->ruleFor($this->s->org['A']->id, LetterDirection::Incoming, 'am')->language)->toBeNull()
        ->and($numbering->ruleFor($this->s->org['B']->id, LetterDirection::Incoming, 'en')->exists)->toBeFalse();
});

it('refuses a numbering pattern without {SEQ} or with unknown tokens', function (): void {
    $admin = $this->s->administrator('A');
    $save = app(SaveNumberingRule::class);

    expect(fn () => $save->execute(['organization_id' => $this->s->org['A']->id, 'direction' => 'outgoing', 'pattern' => 'A/{EC_YEAR}'], $admin))->toThrow(ValidationException::class)
        ->and(fn () => $save->execute(['organization_id' => $this->s->org['A']->id, 'direction' => 'outgoing', 'pattern' => '{SEQ}/{FOO}'], $admin))->toThrow(ValidationException::class);
});

it('renders placeholders escaped and leaves unknown keys empty', function (): void {
    $renderer = app(TemplateRenderer::class);
    $letter = $this->s->outgoing('A', 'B', null, ['body' => '<p>Dear {{ recipient.name }} &amp; {{ nobody }} — {{ note }}</p>', 'template_context' => ['note' => '<b>x</b>']]);
    $context = $renderer->context($letter->fresh(['registrations']), 'en');

    expect($renderer->render($letter->body, $context))->toBe('<p>Dear Bole Sub-city &amp;  — &lt;b&gt;x&lt;/b&gt;</p>')
        ->and($context['sender.name'])->toBe('Trade Bureau')
        ->and($context['letter.number'])->toBe('')
        ->and($renderer->keysIn('{{ a }} and {{a}} and {{ b.c }}'))->toBe(['a', 'b.c']);
});

it('publishes a template as a numbered version and refuses undeclared placeholders', function (): void {
    $steward = $this->s->steward();
    $template = app(SaveTemplate::class)->execute(null, [
        'owner_organization_id' => null, 'code' => 'notice-1', 'title_en' => 'Notice', 'subject' => 'Notice to {{ recipient.name }}', 'body' => '<p>{{ undeclared }}</p>', 'placeholders' => [],
    ], $steward);
    expect($template->code)->toBe('NOTICE-1')->and($template->status->value)->toBe('draft');

    expect(fn () => app(PublishTemplate::class)->execute($template, null, $steward))->toThrow(ValidationException::class);

    app(SaveTemplate::class)->execute($template, ['code' => 'NOTICE-1', 'title_en' => 'Notice', 'subject' => 'Notice to {{ recipient.name }}', 'body' => '<p>{{ undeclared }}</p>', 'placeholders' => [['key' => 'undeclared', 'label_en' => 'Text']]], $steward);
    $version = app(PublishTemplate::class)->execute($template->fresh(), 'v1', $steward);
    expect($version->version_no)->toBe(1)->and($template->fresh()->status->value)->toBe('published')->and($template->fresh()->current_version_no)->toBe(1);

    $again = app(PublishTemplate::class)->execute($template->fresh(), 'v2', $steward);
    expect($again->version_no)->toBe(2);

    // The same code under the same owner is refused.
    expect(fn () => app(SaveTemplate::class)->execute(null, ['owner_organization_id' => null, 'code' => 'NOTICE-1', 'title_en' => 'Dup'], $steward))->toThrow(ValidationException::class);
});

it('issues a draft from a published template through the contract and lists it for the record', function (): void {
    $this->s->platformTemplate();
    $officer = $this->s->officer('A');

    $summary = app(LetterIssuer::class)->issue(new IssueLetterRequest(
        templateCode: 'transmittal', organizationId: $this->s->org['A']->id, regardingType: 'grievance', regardingId: 'G-42', actorId: $officer->id,
        recipientOrganizationId: $this->s->org['B']->id, context: ['document' => 'the hearing minutes'],
    ));

    $letter = Letter::query()->findOrFail($summary->id);
    expect($summary->status)->toBe('draft')->and($summary->direction)->toBe('outgoing')->and($summary->url)->toBe(route('letters.show', $letter, false))
        ->and($letter->template_version_id)->not->toBeNull()->and($letter->regarding_type)->toBe('grievance')->and($letter->template_context)->toBe(['document' => 'the hearing minutes'])
        ->and($letter->subject)->toBe('Transmittal: {{ document }}');

    $renderer = app(TemplateRenderer::class);
    expect($renderer->render($letter->subject, $renderer->context($letter, 'en')))->toBe('Transmittal: the hearing minutes');

    $links = app(LetterLinks::class)->forRecord('grievance', 'G-42');
    expect($links)->toHaveCount(1)->and($links[0]->id)->toBe($letter->id);

    expect(fn () => app(LetterIssuer::class)->issue(new IssueLetterRequest('MISSING', $this->s->org['A']->id, 'grievance', 'G-1', null)))->toThrow(InvalidArgumentException::class);
});
