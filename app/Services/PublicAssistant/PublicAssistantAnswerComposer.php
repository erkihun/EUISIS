<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

use App\Services\Intelligence\AI\AiRequest;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\IntelligenceSettings;
use Throwable;

/**
 * Blocks from the public tools, plus — only when the gateway is available and
 * `public_content` may go outbound — a short model narrative over the tools'
 * public data (P-9). The model never sees anything a tool did not return
 * and nothing in this class can add a link or a tool.
 */
final class PublicAssistantAnswerComposer
{
    private const MAX_INPUT = 4000;

    public function __construct(private readonly LLMGateway $gateway, private readonly IntelligenceSettings $intelligence) {}

    /**
     * @param  list<PublicToolResult>  $results
     * @param  list<array{role: string, text: string}>  $history
     * @return array{text: string, blocks: list<array<string, mixed>>, ai: bool, status: string}
     */
    public function compose(PublicAssistantContext $context, string $mode, string $question, array $results, array $history, bool $narrate = true): array
    {
        $blocks = [];
        $lines = [];
        $data = [];
        foreach ($results as $result) {
            $blocks = [...$blocks, ...$result->blocks];
            $lines = [...$lines, ...$result->lines];
            if ($result->data !== []) {
                $data[$result->tool] = $result->data;
            }
        }
        $composed = ['text' => implode("\n\n", array_filter(array_map('trim', $lines))), 'blocks' => $blocks, 'ai' => false, 'status' => 'ok'];

        $allowed = in_array('public_content', $this->intelligence->outboundDataClasses(), true);
        if (! $narrate || $data === [] || ! $allowed) {
            return $composed;
        }
        if (! $context->aiAvailable) {
            return [...$composed, 'status' => 'fallback'];
        }

        $input = json_encode(['conversation' => $history, 'question' => mb_substr($question, 0, 600), 'page' => $context->kind(), 'public_data' => $data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        try {
            $ai = $this->gateway->complete(new AiRequest(
                feature: 'public_assistant',
                promptKey: 'public_assistant_answer',
                input: mb_substr($input, 0, self::MAX_INPUT),
                dataClasses: ['public_content'],
                variables: ['page' => $context->kind()],
                subjectType: 'public_assistant',
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
                'ai' => true, 'status' => 'ok',
            ];
        }

        return [...$composed, 'status' => 'fallback'];
    }
}
