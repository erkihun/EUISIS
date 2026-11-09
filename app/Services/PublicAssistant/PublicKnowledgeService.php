<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

use App\Models\IntelligenceKnowledgeChunk;
use App\Models\IntelligenceKnowledgeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\PublicSite\Contracts\ReadModels\PublicFaqRecord;
use Modules\PublicSite\Contracts\ReadModels\PublicServiceRecord;

/**
 * The public knowledge filter (P-3, §7–9): documents whose classification
 * is `public`, approved and not bound to an organization, plus published
 * FAQs and published services. The predicate is part of the query — an
 * internal, confidential or draft document is never fetched and therefore
 * never reaches the model.
 */
final class PublicKnowledgeService
{
    private const MAX_TERMS = 8;

    private const MIN_TERM = 3;

    private const STOPWORDS = ['the', 'and', 'for', 'how', 'what', 'can', 'where', 'with', 'this', 'that', 'does', 'from', 'about', 'ነው', 'እና', 'ላይ', 'ውስጥ', 'ምን', 'እንዴት', 'የት', 'ነኝ'];

    /** @return Builder<IntelligenceKnowledgeDocument> */
    public function publicDocuments(): Builder
    {
        return IntelligenceKnowledgeDocument::query()
            ->where('classification', 'public')
            ->where('status', 'approved')
            ->whereNull('organization_id')
            ->whereNull('required_permission');
    }

    /** @return list<array{title: string, heading: ?string, content: string}> */
    public function searchDocuments(string $question, int $limit = 4): array
    {
        if (! Schema::hasTable('intelligence_knowledge_chunks')) {
            return [];
        }
        $terms = self::terms($question);
        if ($terms === []) {
            return [];
        }
        $query = IntelligenceKnowledgeChunk::query()
            ->join('intelligence_knowledge_documents', 'intelligence_knowledge_documents.id', '=', 'intelligence_knowledge_chunks.document_id')
            ->whereIn('intelligence_knowledge_chunks.document_id', $this->publicDocuments()->select('intelligence_knowledge_documents.id'))
            ->select(['intelligence_knowledge_chunks.heading', 'intelligence_knowledge_chunks.content', 'intelligence_knowledge_documents.title', 'intelligence_knowledge_chunks.position']);

        if (DB::getDriverName() === 'pgsql') {
            $query->selectRaw("ts_rank(intelligence_knowledge_chunks.search_vector, plainto_tsquery('simple', ?)) as score", [implode(' ', $terms)])
                ->whereRaw("intelligence_knowledge_chunks.search_vector @@ plainto_tsquery('simple', ?)", [implode(' ', $terms)]);
        } else {
            $cases = [];
            $bindings = [];
            $any = [];
            $anyBindings = [];
            foreach ($terms as $term) {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $cases[] = "(case when lower(intelligence_knowledge_chunks.content) like ? escape '!' or lower(coalesce(intelligence_knowledge_chunks.heading, '')) like ? escape '!' then 1 else 0 end)";
                $bindings[] = $pattern;
                $bindings[] = $pattern;
                $any[] = "lower(intelligence_knowledge_chunks.content) like ? escape '!' or lower(coalesce(intelligence_knowledge_chunks.heading, '')) like ? escape '!'";
                $anyBindings[] = $pattern;
                $anyBindings[] = $pattern;
            }
            $query->selectRaw('('.implode(' + ', $cases).') as score', $bindings)->whereRaw('('.implode(' or ', $any).')', $anyBindings);
        }

        return $query->orderByDesc('score')->orderBy('intelligence_knowledge_documents.title')->orderBy('intelligence_knowledge_chunks.position')
            ->limit(max(1, min(10, $limit)))->get()
            ->map(fn (IntelligenceKnowledgeChunk $row): array => ['title' => (string) $row->title, 'heading' => $row->heading, 'content' => mb_substr((string) $row->content, 0, 700)])
            ->all();
    }

    /** Published FAQs whose question or answer shares terms with the visitor's question, best first. @return list<array{question: string, answer: string}> */
    public function searchFaqs(string $question, string $locale, int $limit = 3): array
    {
        if (! Schema::hasTable('public_faqs')) {
            return [];
        }
        $terms = array_map('mb_strtolower', self::terms($question));
        if ($terms === []) {
            return [];
        }
        $q = $locale === 'am' ? 'question_am' : 'question_en';
        $a = $locale === 'am' ? 'answer_am' : 'answer_en';
        $scored = [];
        foreach (PublicFaqRecord::query()->visible()->orderBy('sort_order')->get([$q, $a, 'question_en', 'answer_en']) as $faq) {
            $questionText = (string) ($faq->{$q} ?: $faq->question_en);
            $answerText = (string) ($faq->{$a} ?: $faq->answer_en);
            $hay = mb_strtolower($questionText.' '.$answerText);
            $score = 0;
            foreach ($terms as $term) {
                if (str_contains($hay, $term)) {
                    $score += str_contains(mb_strtolower($questionText), $term) ? 2 : 1;
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'question' => $questionText, 'answer' => mb_substr(strip_tags($answerText), 0, 900)];
            }
        }
        usort($scored, fn (array $x, array $y): int => $y['score'] <=> $x['score']);

        return array_map(fn (array $r): array => ['question' => $r['question'], 'answer' => $r['answer']], array_slice($scored, 0, $limit));
    }

    /** Published services matching the question. @return list<array{name: string, summary: ?string, requirements: ?string, steps: ?string, href: string}> */
    public function searchServices(string $question, string $locale, int $limit = 3): array
    {
        if (! Schema::hasTable('public_services')) {
            return [];
        }
        $terms = array_map('mb_strtolower', self::terms($question));
        $query = PublicServiceRecord::query()->visible()->orderByDesc('is_featured')->orderBy('sort_order');
        if ($terms !== []) {
            $query->where(function (Builder $w) use ($terms): void {
                foreach ($terms as $term) {
                    $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                    $w->orWhereRaw("lower(name_en) like ? escape '!'", [$pattern])->orWhereRaw("lower(coalesce(name_am, '')) like ? escape '!'", [$pattern])
                        ->orWhereRaw("lower(coalesce(short_description_en, '')) like ? escape '!'", [$pattern])->orWhereRaw("lower(coalesce(short_description_am, '')) like ? escape '!'", [$pattern]);
                }
            });
        }
        $name = $locale === 'am' ? 'name_am' : 'name_en';
        $short = $locale === 'am' ? 'short_description_am' : 'short_description_en';
        $req = $locale === 'am' ? 'requirements_am' : 'requirements_en';
        $steps = $locale === 'am' ? 'steps_am' : 'steps_en';

        return $query->limit(max(1, min(6, $limit)))->get()->map(fn (PublicServiceRecord $s): array => [
            'name' => (string) ($s->{$name} ?: $s->name_en),
            'summary' => $s->{$short} ?: $s->short_description_en,
            'requirements' => $s->{$req} !== null ? mb_substr(strip_tags((string) $s->{$req}), 0, 600) : null,
            'steps' => $s->{$steps} !== null ? mb_substr(strip_tags((string) $s->{$steps}), 0, 600) : null,
            'href' => PublicAssistantPageRegistry::relative(route('public.services.show', $s->slug)),
        ])->all();
    }

    /** @return list<string> */
    public static function terms(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($question))) ?: [];
        $out = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < self::MIN_TERM || in_array($word, self::STOPWORDS, true) || in_array($word, $out, true)) {
                continue;
            }
            $out[] = $word;
            if (count($out) >= self::MAX_TERMS) {
                break;
            }
        }

        return $out;
    }
}
