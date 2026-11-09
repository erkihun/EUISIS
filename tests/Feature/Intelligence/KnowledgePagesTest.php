<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\IntelligenceKnowledgeDocument;
use App\Models\IntelligenceKnowledgeQuestion;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Intelligence\AI\AiResult;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\Knowledge\KnowledgeDocumentService;
use App\Services\Intelligence\Knowledge\KnowledgeRetriever;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeLLMGateway;
use Tests\Support\SituationRoomScenario;

/*
 * Knowledge Assistant (docs/intelligence-knowledge.md): retrieval filters
 * live in the query (status, classification, required permission,
 * organization scope), the gateway only ever sees permitted passages, AI off
 * means quoted passages, and documents are managed with the governance grant.
 */

function knowledgeUser(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('knowledge-'.md5(implode(',', $permissions)), 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

/** @param  array<string, mixed>  $extra */
function approvedDocument(User $author, string $title, string $body, array $extra = []): IntelligenceKnowledgeDocument
{
    $service = app(KnowledgeDocumentService::class);
    $document = $service->create($author, $extra + ['title' => $title, 'body' => $body, 'classification' => 'internal', 'language' => 'en']);

    return $service->approve($author, $document);
}

function knowledgeSetting(string $key, string $value): void
{
    SystemSetting::query()->updateOrCreate(['group' => 'intelligence', 'key' => $key], ['value' => $value, 'type' => 'string', 'label_en' => $key, 'label_am' => $key]);
    app(SystemSettingsService::class)->clearCache();
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    foreach ([...IntelligencePermissions::all(), 'intelligence.workforce.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('retrieval returns only approved documents the reader may open, and ranks the matching chunk first', function (): void {
    $scenario = SituationRoomScenario::build();
    $manager = knowledgeUser([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE, IntelligencePermissions::GOVERNANCE_MANAGE]);
    $service = app(KnowledgeDocumentService::class);

    approvedDocument($manager, 'Leave policy', "# Leave\n\nAnnual leave accrues monthly.\n\n# Sick leave\n\nSick leave needs a medical certificate after three days.");
    $service->create($manager, ['title' => 'Draft leave note', 'body' => 'Annual leave draft text that is not approved.', 'classification' => 'internal', 'language' => 'en']);
    approvedDocument($manager, 'Restricted leave memo', 'Annual leave restricted memo.', ['classification' => 'restricted']);
    approvedDocument($manager, 'Confidential leave memo', 'Annual leave confidential memo.', ['classification' => 'confidential']);
    approvedDocument($manager, 'Workforce leave guide', 'Annual leave workforce guide for analysts.', ['required_permission' => 'intelligence.workforce.view']);
    approvedDocument($manager, 'Subcity B leave rules', 'Annual leave rules for Subcity B.', ['organization_id' => $scenario->org['subcity_b']->id]);
    approvedDocument($manager, 'Woreda A1 leave rules', 'Annual leave rules for Woreda A1.', ['organization_id' => $scenario->org['woreda_a1']->id]);

    $reader = $scenario->viewer([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE], 'subcity_a');
    $titles = array_column(app(KnowledgeRetriever::class)->search($reader, 'annual leave', 20), 'title');

    expect($titles)->toContain('Leave policy', 'Woreda A1 leave rules')
        ->not->toContain('Draft leave note', 'Restricted leave memo', 'Confidential leave memo', 'Workforce leave guide', 'Subcity B leave rules');

    $analyst = $scenario->viewer([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE, 'intelligence.workforce.view'], 'subcity_a');
    expect(array_column(app(KnowledgeRetriever::class)->search($analyst, 'annual leave', 20), 'title'))->toContain('Workforce leave guide')->not->toContain('Restricted leave memo');

    $governance = knowledgeUser([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE, IntelligencePermissions::GOVERNANCE]);
    expect(array_column(app(KnowledgeRetriever::class)->search($governance, 'annual leave', 20), 'title'))->toContain('Confidential leave memo')->not->toContain('Restricted leave memo');
    expect(array_column(app(KnowledgeRetriever::class)->search($manager, 'annual leave', 20), 'title'))->toContain('Restricted leave memo', 'Confidential leave memo');

    $hits = app(KnowledgeRetriever::class)->search($reader, 'medical certificate sick');
    expect($hits[0]['heading'])->toBe('Sick leave')->and($hits[0]['score'])->toBeGreaterThan($hits[1]['score'] ?? 0);
});

test('asking with AI off records an extractive question with quoted passages; without a match the answer is honest', function (): void {
    $reader = knowledgeUser([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE]);
    approvedDocument($reader, 'Transfer procedure', "# Transfers\n\nA transfer request needs the receiving office's consent.");

    $this->actingAs($reader)->get(route('intelligence.knowledge.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Knowledge/Index')->where('documents_count', 1)->where('ai.enabled', false)->where('can.manage', false)->has('questions', 0));

    $this->actingAs($reader)->post(route('intelligence.knowledge.ask'), ['question' => 'What does a transfer request need?'])
        ->assertRedirect(route('intelligence.knowledge.index'))
        ->assertSessionHas('knowledge_answer.mode', 'extractive')
        ->assertSessionHas('knowledge_answer.grounded', true)
        ->assertSessionHas('knowledge_answer.notice', 'ai_off');
    $question = IntelligenceKnowledgeQuestion::query()->firstOrFail();
    expect($question->mode)->toBe('extractive')->and($question->grounded)->toBeTrue()->and($question->answer)->toBeNull()
        ->and($question->citations[0]['title'])->toBe('Transfer procedure')
        ->and(AuditLog::query()->where('event_type', AuditEventType::IntelligenceKnowledgeAsked->value)->value('new_values'))->not->toContain('transfer');

    $this->actingAs($reader)->post(route('intelligence.knowledge.ask'), ['question' => 'Where is the cafeteria menu?'])
        ->assertSessionHas('knowledge_answer.grounded', false)->assertSessionHas('knowledge_answer.notice', 'no_passages');
    $this->actingAs($reader)->post(route('intelligence.knowledge.ask'), ['question' => 'hi'])->assertSessionHasErrors('question');

    $this->actingAs($reader)->get(route('intelligence.knowledge.index'))
        ->assertInertia(fn (Assert $page) => $page->has('questions', 2)->where('questions.0.grounded', false)->where('questions.1.grounded', true));

    knowledgeSetting('knowledge_enabled', '0');
    $this->actingAs($reader)->post(route('intelligence.knowledge.ask'), ['question' => 'What does a transfer request need?'])
        ->assertSessionHas('knowledge_answer.mode', 'none')->assertSessionHas('knowledge_answer.notice', 'disabled');
});

test('asking with a gateway records an AI answer with resolved citations, drops out-of-range ones and never sends a restricted passage', function (): void {
    knowledgeSetting('ai_outbound_data_classes', 'aggregates,knowledge_documents');
    $fake = new FakeLLMGateway(new AiResult(ok: true, json: ['answer' => 'A transfer needs consent [1].', 'citations' => [1, 7, 1], 'grounded' => true, 'confidence' => 'high'], runId: 'run-1'));
    $this->app->instance(LLMGateway::class, $fake);

    $manager = knowledgeUser([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE, IntelligencePermissions::GOVERNANCE_MANAGE]);
    $reader = knowledgeUser([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE]);
    approvedDocument($manager, 'Transfer procedure', "# Transfers\n\nA transfer request needs the receiving office's consent.");
    approvedDocument($manager, 'Restricted transfer memo', 'SECRET-MEMO: transfer request consent exceptions for executives.', ['classification' => 'restricted']);

    $this->actingAs($reader)->post(route('intelligence.knowledge.ask'), ['question' => 'What does a transfer request need?'])
        ->assertRedirect()->assertSessionHas('knowledge_answer.mode', 'ai')->assertSessionHas('knowledge_answer.run_id', 'run-1');

    $question = IntelligenceKnowledgeQuestion::query()->firstOrFail();
    expect($question->mode)->toBe('ai')->and($question->answer)->toBe('A transfer needs consent [1].')->and($question->grounded)->toBeTrue()
        ->and($question->citations)->toHaveCount(1)->and($question->citations[0]['title'])->toBe('Transfer procedure')->and($question->citations[0]['n'])->toBe(1)
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]->dataClasses)->toBe(['knowledge_documents'])
        ->and($fake->requests[0]->promptKey)->toBe('knowledge_answer')
        ->and($fake->lastInput())->toContain('[1] Transfer procedure (§Transfers)')->not->toContain('SECRET-MEMO');

    // The manager may read the restricted memo, so for them it is sent; the reader above never saw it.
    $this->actingAs($manager)->post(route('intelligence.knowledge.ask'), ['question' => 'transfer request consent exceptions']);
    expect($fake->lastInput())->toContain('SECRET-MEMO');

    // A refused or unavailable gateway falls back to quoted passages.
    $fake->result = AiResult::unavailable(AiResult::REFUSED, 'daily_cap_reached');
    $this->actingAs($reader)->post(route('intelligence.knowledge.ask'), ['question' => 'What does a transfer request need?'])
        ->assertSessionHas('knowledge_answer.mode', 'extractive')->assertSessionHas('knowledge_answer.notice', 'refused')
        ->assertSessionHas('knowledge_answer.grounded', true);
    expect(IntelligenceKnowledgeQuestion::query()->where('mode', 'extractive')->count())->toBe(1);

    // Nothing is sent when the gateway says it is unavailable: the passages are quoted without a call.
    $fake->isAvailable = false;
    $sent = count($fake->requests);
    $this->actingAs($reader)->post(route('intelligence.knowledge.ask'), ['question' => 'What does a transfer request need?'])
        ->assertSessionHas('knowledge_answer.mode', 'extractive')->assertSessionHas('knowledge_answer.notice', 'ai_off');
    expect($fake->requests)->toHaveCount($sent);
});

test('documents are managed with the governance grant: create chunks, approve shows, retire hides, delete is audited', function (): void {
    $reader = knowledgeUser([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE]);
    $manager = knowledgeUser([IntelligencePermissions::VIEW, IntelligencePermissions::KNOWLEDGE, IntelligencePermissions::GOVERNANCE_MANAGE]);
    $payload = ['title' => 'Grievance handling', 'body' => "# Intake\n\nA grievance is acknowledged within three working days.\n\n# Hearing\n\nThe committee hears both sides.", 'classification' => 'internal', 'language' => 'en'];

    $this->actingAs($reader)->get(route('intelligence.knowledge.documents.index'))->assertForbidden();
    $this->actingAs($reader)->post(route('intelligence.knowledge.documents.store'), $payload)->assertForbidden();
    $this->actingAs(knowledgeUser([]))->get(route('intelligence.knowledge.index'))->assertForbidden();

    $this->actingAs($manager)->post(route('intelligence.knowledge.documents.store'), $payload)->assertRedirect()->assertSessionHas('flash.type', 'success');
    $document = IntelligenceKnowledgeDocument::query()->where('title', 'Grievance handling')->firstOrFail();
    expect($document->status)->toBe('draft')->and($document->chunks()->count())->toBe(2)->and($document->slug)->toBe('grievance-handling');
    expect(app(KnowledgeRetriever::class)->search($reader, 'grievance acknowledged'))->toBe([]);

    $this->actingAs($manager)->post(route('intelligence.knowledge.documents.store'), $payload + ['required_permission' => 'not.a.permission'])->assertSessionHasErrors('required_permission');
    $this->actingAs($manager)->post(route('intelligence.knowledge.documents.store'), $payload + ['organization_id' => 'not-a-uuid'])->assertSessionHasErrors('organization_id');

    $this->actingAs($manager)->patch(route('intelligence.knowledge.documents.update', $document), ['status' => 'approved'])->assertRedirect();
    expect($document->fresh()->status)->toBe('approved')->and($document->fresh()->approved_by)->toBe($manager->id)
        ->and(array_column(app(KnowledgeRetriever::class)->search($reader, 'grievance acknowledged'), 'heading'))->toBe(['Intake']);

    // A body change is a new draft version with fresh chunks.
    $this->actingAs($manager)->patch(route('intelligence.knowledge.documents.update', $document), ['body' => "# Intake\n\nA grievance is acknowledged within five working days."])->assertRedirect();
    $document->refresh();
    expect($document->status)->toBe('draft')->and($document->version)->toBe(2)->and($document->chunks()->count())->toBe(1);

    $this->actingAs($manager)->patch(route('intelligence.knowledge.documents.update', $document), ['status' => 'approved']);
    $this->actingAs($manager)->patch(route('intelligence.knowledge.documents.update', $document), ['status' => 'retired'])->assertRedirect();
    expect($document->fresh()->status)->toBe('retired')->and(app(KnowledgeRetriever::class)->search($reader, 'grievance acknowledged'))->toBe([]);

    $this->actingAs($manager)->get(route('intelligence.knowledge.documents.index', ['status' => 'retired']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Knowledge/Documents')->has('documents.data', 1)->where('counts.retired', 1)->where('documents.data.0.chunks', 1));

    $this->actingAs($manager)->delete(route('intelligence.knowledge.documents.destroy', $document))->assertRedirect(route('intelligence.knowledge.documents.index'));
    expect(IntelligenceKnowledgeDocument::query()->count())->toBe(0);
    $audits = AuditLog::query()->where('event_type', AuditEventType::IntelligenceKnowledgeDocumentChanged->value)->get();
    expect($audits->pluck('new_values.action')->all())->toBe(['created', 'approved', 'revised', 'approved', 'retired', 'deleted'])
        ->and(json_encode($audits->pluck('new_values')))->not->toContain('acknowledged');
});

test('the import command imports repository Markdown as repo_doc documents and skips unchanged files', function (): void {
    knowledgeUser([IntelligencePermissions::VIEW]);
    $folder = storage_path('framework/testing/knowledge-'.uniqid());
    File::ensureDirectoryExists($folder.'/nested');
    File::put($folder.'/leave.md', "---\nowner: hr\n---\n# Leave policy\n\nAnnual leave accrues monthly.");
    File::put($folder.'/nested/transfers.md', "Transfers need consent.\n\n## Steps\n\nFile the request.");
    File::put($folder.'/notes.txt', 'Not markdown.');
    $relative = str_replace('\\', '/', substr($folder, strlen(base_path()) + 1));

    try {
        $this->artisan('intelligence:knowledge-import', ['--path' => $relative])->expectsOutputToContain('Imported 2 new, revised 0, skipped 0')->assertSuccessful();
        $leave = IntelligenceKnowledgeDocument::query()->where('source_path', $relative.'/leave.md')->firstOrFail();
        expect($leave->title)->toBe('Leave policy')->and($leave->source_type)->toBe('repo_doc')->and($leave->status)->toBe('draft')->and($leave->classification)->toBe('internal')
            ->and($leave->chunks()->count())->toBe(1)
            ->and(IntelligenceKnowledgeDocument::query()->where('source_path', $relative.'/nested/transfers.md')->value('title'))->toBe('transfers');

        $this->artisan('intelligence:knowledge-import', ['--path' => $relative, '--approve' => true])->expectsOutputToContain('Imported 0 new, revised 0, skipped 2')->assertSuccessful();
        expect($leave->fresh()->status)->toBe('draft');

        File::put($folder.'/leave.md', "# Leave policy\n\nAnnual leave accrues monthly and carries over.");
        $this->artisan('intelligence:knowledge-import', ['--path' => $relative, '--approve' => true])->expectsOutputToContain('Imported 0 new, revised 1, skipped 1')->assertSuccessful();
        expect($leave->fresh()->status)->toBe('approved')->and($leave->fresh()->version)->toBe(2);

        $this->artisan('intelligence:knowledge-import', ['--path' => '../'])->assertFailed();
        $this->artisan('intelligence:knowledge-import', ['--path' => $relative, '--classification' => 'secret'])->assertFailed();
    } finally {
        File::deleteDirectory($folder);
    }
});
