<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Assistant\Tools\ToolResult;
use App\Services\Intelligence\AI\AiRequest;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\IntelligenceSettings;
use Throwable;

/**
 * Turns tool results into one assistant message (docs/assistant/context-and-tools.md).
 * The blocks are the tools' own. The text is, when the gateway is available
 * and the data classes allow it, a short model narrative over the tools'
 * compact data (validated JSON, shown as text); otherwise the tools'
 * deterministic lines. Either way the figures never come from the model.
 *
 * @phpstan-type Composed array{text: string, blocks: list<array<string, mixed>>, ai: bool, run_id: ?string, confidence: ?string, status: string}
 */
final class AssistantAnswerComposer
{
    private const MAX_INPUT = 6000;

    public function __construct(
        private readonly LLMGateway $gateway,
        private readonly IntelligenceSettings $intelligence,
    ) {}

    /**
     * @param  list<ToolResult>  $results
     * @param  list<array{role: string, text: string}>  $history
     * @return array{text: string, blocks: list<array<string, mixed>>, ai: bool, run_id: ?string, confidence: ?string, status: string}
     */
    public function compose(AssistantContext $context, string $mode, string $question, array $results, array $history, bool $narrate = true): array
    {
        $blocks = [];
        $lines = [];
        $dataClasses = [];
        $data = [];
        $status = 'ok';
        $aiAlreadyUsed = false;
        foreach ($results as $result) {
            $blocks = [...$blocks, ...$result->blocks];
            $lines = [...$lines, ...$result->lines];
            if ($result->dataClass !== null) {
                $dataClasses[$result->dataClass] = true;
            }
            if ($result->data !== []) {
                $data[$result->tool] = $result->data;
            }
            if ($result->status === 'denied') {
                $status = 'denied';
            }
            if (! empty($result->data['ai'])) {
                $aiAlreadyUsed = true; // the knowledge assistant already phrased its own answer
            }
        }

        // Paragraph breaks: the panel's renderer joins single newlines into one paragraph.
        $text = implode("\n\n", array_filter(array_map('trim', $lines)));
        $composed = ['text' => $text, 'blocks' => $blocks, 'ai' => $aiAlreadyUsed, 'run_id' => null, 'confidence' => null, 'status' => $status];

        $classes = array_keys($dataClasses);
        $allowed = $classes !== [] && array_diff($classes, $this->intelligence->outboundDataClasses()) === [];
        if (! $narrate || $aiAlreadyUsed || $status === 'denied' || $data === [] || ! $allowed || ! $context->aiAvailable) {
            if ($narrate && ! $aiAlreadyUsed && $status !== 'denied' && $data !== [] && $allowed && ! $context->aiAvailable) {
                $composed['status'] = 'fallback';
            }

            return $composed;
        }

        $input = json_encode(['conversation' => $history, 'question' => mb_substr($question, 0, 1000), 'tools' => $data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        try {
            $ai = $this->gateway->complete(new AiRequest(
                feature: 'assistant',
                promptKey: 'assistant_answer',
                input: mb_substr($input, 0, self::MAX_INPUT),
                dataClasses: $classes,
                variables: ['mode' => $mode, 'module' => $context->page['module_label']],
                triggeredByUserId: $context->user->id,
                subjectType: 'assistant',
                locale: $context->locale,
            ));
        } catch (Throwable $e) {
            report($e);

            return [...$composed, 'status' => 'fallback'];
        }

        if ($ai->ok && is_array($ai->json) && is_string($ai->json['answer'] ?? null) && trim($ai->json['answer']) !== '') {
            return [
                'text' => trim($ai->json['answer']),
                'blocks' => [['type' => 'text', 'text' => trim($ai->json['answer'])], ...array_values(array_filter($blocks, fn (array $b): bool => $b['type'] !== 'text'))],
                'ai' => true, 'run_id' => $ai->runId, 'confidence' => is_string($ai->json['confidence'] ?? null) ? $ai->json['confidence'] : null, 'status' => 'ok',
            ];
        }

        return [...$composed, 'run_id' => $ai->runId, 'status' => 'fallback'];
    }
}
