<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Models\DesignDocumentSuggestion;
use App\Models\InstitutionMandate;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\User;
use App\Services\Intelligence\AI\AiRequest;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\IntelligenceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Document-assisted capture (brief §75–77, ADR-LB-006).
 *
 *   passage ─▶ AI gateway (knowledge_documents class, redacted, schema-bound)
 *           ─▶ suggestions with "uncertain" marks ─▶ a person applies or rejects each
 *
 * The assistant never writes a record: applying an item creates a function
 * or power under the mandate through FunctionService, exactly as a person
 * typing it would — and a mandate that is published cannot take new ones
 * without its own new version. When the gateway is off, out of budget or refuses
 * the data class, the result is "unavailable" and capture carries on by hand.
 */
class DesignSuggestionService
{
    public const MAX_PASSAGE = 12000;

    public function __construct(
        private readonly LLMGateway $gateway,
        private readonly IntelligenceSettings $settings,
        private readonly FunctionService $functions,
        private readonly OrganizationDesignAudit $audit,
    ) {}

    public function suggestFunctions(User $actor, InstitutionMandate $mandate, string $passage, ?string $legalSourceId, string $locale): DesignDocumentSuggestion
    {
        $passage = trim($passage);
        if ($passage === '') {
            throw ValidationException::withMessages(['passage' => __('organization-design.suggestion_passage_required')]);
        }
        $passage = mb_substr($passage, 0, self::MAX_PASSAGE);

        $suggestion = new DesignDocumentSuggestion([
            'organization_id' => $mandate->organization_id, 'mandate_id' => $mandate->getKey(), 'legal_source_id' => $legalSourceId,
            'kind' => 'functions', 'passage_sha256' => hash('sha256', $passage), 'passage_chars' => mb_strlen($passage), 'created_by' => $actor->getKey(),
        ]);

        if (! $this->gateway->available() || ! in_array('knowledge_documents', $this->settings->outboundDataClasses(), true)) {
            $suggestion->forceFill(['status' => DesignDocumentSuggestion::STATUS_UNAVAILABLE])->save();

            return $suggestion;
        }

        $result = $this->gateway->complete(new AiRequest(
            feature: 'organization_design',
            promptKey: 'design_extraction',
            input: $passage,
            dataClasses: ['knowledge_documents'],
            triggeredByUserId: $actor->id,
            subjectType: $mandate->getMorphClass(),
            subjectId: (string) $mandate->getKey(),
            locale: $locale,
        ));

        $items = [];
        if ($result->ok && is_array($result->json['items'] ?? null)) {
            foreach ($result->json['items'] as $item) {
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $items[] = [
                    'title' => mb_substr($title, 0, 500),
                    'kind' => ($item['kind'] ?? 'function') === 'power' ? 'power' : 'function',
                    'legal_reference' => mb_substr(trim((string) ($item['legal_reference'] ?? '')), 0, 120) ?: null,
                    'uncertain' => (bool) ($item['uncertain'] ?? true),
                    'state' => 'pending',
                    'record_id' => null,
                ];
            }
        }

        $suggestion->forceFill([
            'status' => ! $result->ok ? ($result->reason === 'unavailable' ? DesignDocumentSuggestion::STATUS_UNAVAILABLE : DesignDocumentSuggestion::STATUS_FAILED) : ($items === [] ? DesignDocumentSuggestion::STATUS_EMPTY : DesignDocumentSuggestion::STATUS_READY),
            'items' => $items ?: null,
            'notes' => is_string($result->json['notes'] ?? null) ? mb_substr($result->json['notes'], 0, 600) : null,
            'ai_run_id' => $result->runId,
        ])->save();
        $this->audit->record(AuditEventType::DesignSuggestionGenerated, $actor, $mandate, ['suggestion_id' => $suggestion->getKey(), 'status' => $suggestion->status, 'items' => count($items), 'passage_sha256' => $suggestion->passage_sha256]);

        return $suggestion;
    }

    /**
     * A person accepts one item, possibly after correcting it: it becomes a
     * DRAFT function (or power) of the mandate. Code and wording are the
     * person's; the assistant's text is only the starting point.
     *
     * @param  array{code: string, title_en?: string|null, title_am?: string|null, legal_reference?: string|null}  $data
     */
    public function apply(User $actor, DesignDocumentSuggestion $suggestion, int $index, array $data): OrganizationFunction|OrganizationPower
    {
        $items = $suggestion->items ?? [];
        if (! isset($items[$index]) || $items[$index]['state'] !== 'pending') {
            throw ValidationException::withMessages(['item' => __('organization-design.suggestion_item_unavailable')]);
        }
        $item = $items[$index];
        $mandate = $suggestion->mandate;

        return DB::transaction(function () use ($actor, $suggestion, $index, $data, $item, $items, $mandate): OrganizationFunction|OrganizationPower {
            $fields = [
                'code' => $data['code'],
                'title_en' => ($data['title_en'] ?? null) ?: $item['title'],
                'title_am' => $data['title_am'] ?? null,
                'legal_reference' => ($data['legal_reference'] ?? null) ?: $item['legal_reference'],
            ];
            $record = $item['kind'] === 'power'
                ? $this->functions->createPower($actor, $mandate, $fields)
                : $this->functions->createFunction($actor, $mandate, $fields);

            $items[$index] = [...$item, 'state' => 'applied', 'record_id' => $record->getKey()];
            $suggestion->forceFill(['items' => $items])->save();
            $this->audit->record(AuditEventType::DesignSuggestionApplied, $actor, $mandate, ['suggestion_id' => $suggestion->getKey(), 'item' => $index, 'record_id' => $record->getKey(), 'kind' => $item['kind']]);

            return $record;
        });
    }

    public function reject(User $actor, DesignDocumentSuggestion $suggestion, int $index): void
    {
        $items = $suggestion->items ?? [];
        if (! isset($items[$index]) || $items[$index]['state'] !== 'pending') {
            throw ValidationException::withMessages(['item' => __('organization-design.suggestion_item_unavailable')]);
        }
        $items[$index]['state'] = 'rejected';
        $suggestion->forceFill(['items' => $items])->save();
        $this->audit->record(AuditEventType::DesignSuggestionRejected, $actor, $suggestion->mandate, ['suggestion_id' => $suggestion->getKey(), 'item' => $index]);
    }
}
