<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Knowledge;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IntelligenceKnowledgeQuestion;
use App\Models\User;
use App\Services\Intelligence\AI\AiRequest;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\AI\Redactor;
use App\Services\Intelligence\IntelligenceSettings;

/**
 * Retrieval-grounded question answering (brief §20–22, §61). The viewer's
 * question is redacted, the permitted passages are retrieved (KnowledgeRetriever
 * filters in SQL), and then either the gateway synthesises a cited answer from
 * those passages alone, or — AI off, refused, unavailable or failed — the
 * matching passages are quoted as they are, with no synthesised answer.
 * Nothing is ever answered from outside the approved knowledge base.
 */
final class KnowledgeAssistant
{
    public const MAX_PASSAGES = 6;

    public const EXTRACTIVE_PASSAGES = 3;

    public function __construct(
        private readonly KnowledgeRetriever $retriever,
        private readonly LLMGateway $gateway,
        private readonly IntelligenceSettings $settings,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @return array{mode: string, question: string, answer: ?string, grounded: bool, citations: list<array<string, mixed>>, confidence: ?string, run_id: ?string, passages: list<array<string, mixed>>, notice: ?string}
     */
    public function ask(User $user, string $question, string $locale = 'en'): array
    {
        $question = mb_substr(Redactor::text(trim($question)), 0, 1000);
        $result = ['mode' => 'none', 'question' => $question, 'answer' => null, 'grounded' => false, 'citations' => [], 'confidence' => null, 'run_id' => null, 'passages' => [], 'notice' => null];

        if (! $this->settings->knowledgeEnabled()) {
            $result['notice'] = 'disabled';

            return $this->finish($user, $result);
        }

        $chunks = $this->retriever->search($user, $question, self::MAX_PASSAGES);
        if ($chunks === []) {
            $result['mode'] = 'extractive';
            $result['notice'] = 'no_passages';

            return $this->finish($user, $result);
        }

        if ($this->gateway->available() && in_array('knowledge_documents', $this->settings->outboundDataClasses(), true)) {
            $ai = $this->gateway->complete(new AiRequest(
                feature: 'knowledge_assistant',
                promptKey: 'knowledge_answer',
                input: self::passagesInput($chunks),
                dataClasses: ['knowledge_documents'],
                variables: ['question' => $question],
                triggeredByUserId: $user->id,
                locale: $locale,
            ));
            $result['run_id'] = $ai->runId;
            if ($ai->ok && is_array($ai->json)) {
                $result['mode'] = 'ai';
                $result['answer'] = is_string($ai->json['answer'] ?? null) ? $ai->json['answer'] : null;
                $result['grounded'] = (bool) ($ai->json['grounded'] ?? false);
                $result['confidence'] = is_string($ai->json['confidence'] ?? null) ? $ai->json['confidence'] : null;
                $result['citations'] = $this->resolveCitations($chunks, is_array($ai->json['citations'] ?? null) ? $ai->json['citations'] : []);
                if ($result['answer'] === null || trim($result['answer']) === '') {
                    $result['grounded'] = false;
                }

                return $this->finish($user, $result);
            }
            // Refused, unavailable, failed or invalid: fall back to the quoted passages, never to a guess.
            $result['notice'] = $ai->reason ?? 'unavailable';
        } else {
            $result['notice'] = 'ai_off';
        }

        $top = array_slice($chunks, 0, self::EXTRACTIVE_PASSAGES);
        $result['mode'] = 'extractive';
        $result['grounded'] = $top !== [];
        $result['passages'] = array_map(fn (array $c, int $i): array => ['n' => $i + 1, ...$c], $top, array_keys($top));
        $result['citations'] = $this->resolveCitations($chunks, range(1, count($top)));

        return $this->finish($user, $result);
    }

    /**
     * The DATA block: numbered passages, title and section first, the text after. The prompt fences it;
     * the content is data to the model whatever it says.
     *
     * @param  list<array{title: string, heading: ?string, content: string}>  $chunks
     */
    public static function passagesInput(array $chunks): string
    {
        $lines = [];
        foreach ($chunks as $i => $chunk) {
            $section = $chunk['heading'] !== null && $chunk['heading'] !== '' ? ' (§'.$chunk['heading'].')' : '';
            $lines[] = '['.($i + 1).'] '.$chunk['title'].$section."\n".$chunk['content'];
        }

        return implode("\n\n", $lines);
    }

    /**
     * Citations the model returned, kept only when they name a passage that was actually sent (1..n), resolved to
     * the document and chunk they point at. Anything else is dropped: a citation that was not in the DATA is not evidence.
     *
     * @param  list<array<string, mixed>>  $chunks
     * @param  list<mixed>  $numbers
     * @return list<array{n: int, document_id: string, title: string, chunk_id: string, position: int, heading: ?string}>
     */
    private function resolveCitations(array $chunks, array $numbers): array
    {
        $out = [];
        $seen = [];
        foreach ($numbers as $n) {
            if (! is_int($n) && ! (is_string($n) && ctype_digit($n))) {
                continue;
            }
            $n = (int) $n;
            if ($n < 1 || $n > count($chunks) || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $chunk = $chunks[$n - 1];
            $out[] = ['n' => $n, 'document_id' => $chunk['document_id'], 'title' => $chunk['title'], 'chunk_id' => $chunk['chunk_id'], 'position' => $chunk['position'], 'heading' => $chunk['heading']];
        }

        return $out;
    }

    /**
     * Store the question and audit that it was asked: length and mode only, never the text.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(User $user, array $result): array
    {
        $row = IntelligenceKnowledgeQuestion::query()->create([
            'user_id' => $user->id,
            'question' => $result['question'],
            'answer' => $result['answer'],
            'grounded' => $result['grounded'],
            'citations' => $result['citations'],
            'ai_run_id' => $result['run_id'],
            'mode' => $result['mode'],
            'created_at' => now(),
        ]);
        $this->audit->execute(AuditEventType::IntelligenceKnowledgeAsked, $user, $row, newValues: [
            'question_length' => mb_strlen($result['question']),
            'mode' => $result['mode'],
            'grounded' => $result['grounded'],
            'citations' => count($result['citations']),
        ]);

        return ['id' => $row->id, 'asked_at' => $row->created_at->toIso8601String(), ...$result];
    }
}
