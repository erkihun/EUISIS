<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Knowledge;

use App\Models\IntelligenceKnowledgeChunk;
use App\Models\IntelligenceKnowledgeDocument;
use App\Models\User;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Permission-aware retrieval (brief §20–22, D-8). Every filter is part of the
 * SQL — status, classification, required permission, organization scope — so
 * a passage the viewer may not open is never read from the database, let
 * alone sent to a model or hidden client-side. Ranking is PostgreSQL
 * full-text (`ts_rank` over the generated tsvector) or, on the other drivers,
 * a LIKE score: how many of the question's terms the chunk contains.
 */
final class KnowledgeRetriever
{
    /** Terms shorter than this carry no signal and would make every LIKE match. */
    private const MIN_TERM_LENGTH = 3;

    /** Bound on the number of LIKE predicates one question may produce. */
    private const MAX_TERMS = 8;

    /**
     * Function words that would match nearly every passage. The `simple` text-search configuration
     * keeps no stop list of its own, so this applies to both the LIKE and the tsquery path.
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'had', 'has', 'have', 'was', 'were', 'one', 'our', 'out', 'its',
        'what', 'when', 'where', 'which', 'who', 'whom', 'why', 'how', 'does', 'did', 'with', 'this', 'that', 'these', 'those', 'from', 'into',
        'than', 'then', 'they', 'them', 'their', 'there', 'will', 'would', 'should', 'could', 'about', 'your', 'she', 'his', 'her', 'him',
        'may', 'must', 'shall', 'also', 'been', 'being', 'some', 'such', 'only', 'other', 'more', 'most', 'need', 'needs', 'please', 'tell',
        'ነው', 'ናቸው', 'ምንድን', 'እንዴት', 'የት', 'መቼ', 'ለምን', 'ማን', 'እና', 'ወይም', 'ግን', 'ስለ', 'ውስጥ', 'ላይ', 'ነበር', 'አለ', 'የለም', 'ይህ', 'ያ', 'እነዚህ',
    ];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    /**
     * The approved documents this viewer may read. The required-permission
     * filter is resolved from the small set of distinct values present: those
     * the viewer holds become an IN list, the rest never leave the query.
     *
     * @return Builder<IntelligenceKnowledgeDocument>
     */
    public function visibleDocuments(User $user): Builder
    {
        $query = IntelligenceKnowledgeDocument::query()->where('intelligence_knowledge_documents.status', 'approved');

        // Classification: restricted needs the governance-manage grant; confidential the governance-view grant,
        // or a required_permission the viewer holds (checked by the permission filter below).
        $classes = ['public', 'internal'];
        $manage = $user->can(IntelligencePermissions::GOVERNANCE_MANAGE);
        $view = $manage || $user->can(IntelligencePermissions::GOVERNANCE);
        if ($view) {
            $classes[] = 'confidential';
        }
        if ($manage) {
            $classes[] = 'restricted';
        }
        $query->where(function (Builder $q) use ($classes): void {
            $q->whereIn('intelligence_knowledge_documents.classification', $classes)
                ->orWhere(function (Builder $c): void {
                    $c->where('intelligence_knowledge_documents.classification', 'confidential')->whereNotNull('intelligence_knowledge_documents.required_permission');
                });
        });

        $held = $this->heldPermissions($user);
        $query->where(function (Builder $q) use ($held): void {
            $q->whereNull('intelligence_knowledge_documents.required_permission');
            if ($held !== []) {
                $q->orWhereIn('intelligence_knowledge_documents.required_permission', $held);
            }
        });

        // Organization scope: city-wide documents (null) plus those inside the viewer's scope.
        if (! $this->scope->isUnrestricted($user)) {
            $query->where(function (Builder $q) use ($user): void {
                $q->whereNull('intelligence_knowledge_documents.organization_id')
                    ->orWhere(fn (Builder $o) => $this->scope->applyOrganizationScope($o, $user, 'intelligence_knowledge_documents.organization_id'));
            });
        }

        return $query;
    }

    /**
     * The best-matching chunks of the visible documents.
     *
     * @return list<array{chunk_id: string, document_id: string, title: string, classification: string, position: int, heading: ?string, content: string, score: float}>
     */
    public function search(User $user, string $question, int $limit = 6): array
    {
        $terms = self::terms($question);
        if ($terms === []) {
            return [];
        }
        $limit = max(1, min(20, $limit));

        $query = IntelligenceKnowledgeChunk::query()
            ->join('intelligence_knowledge_documents', 'intelligence_knowledge_documents.id', '=', 'intelligence_knowledge_chunks.document_id')
            ->whereIn('intelligence_knowledge_chunks.document_id', $this->visibleDocuments($user)->select('intelligence_knowledge_documents.id'))
            ->select([
                'intelligence_knowledge_chunks.id as chunk_id', 'intelligence_knowledge_chunks.document_id', 'intelligence_knowledge_chunks.position',
                'intelligence_knowledge_chunks.heading', 'intelligence_knowledge_chunks.content',
                'intelligence_knowledge_documents.title', 'intelligence_knowledge_documents.classification',
            ]);

        if (DB::getDriverName() === 'pgsql') {
            $query->selectRaw("ts_rank(intelligence_knowledge_chunks.search_vector, plainto_tsquery('simple', ?)) as score", [implode(' ', $terms)])
                ->whereRaw("intelligence_knowledge_chunks.search_vector @@ plainto_tsquery('simple', ?)", [implode(' ', $terms)]);
        } else {
            // `!` is the LIKE escape character: a backslash would need different quoting on MySQL and PostgreSQL.
            $cases = [];
            $caseBindings = [];
            $matchAny = [];
            $anyBindings = [];
            foreach ($terms as $term) {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $cases[] = "(case when lower(intelligence_knowledge_chunks.content) like ? escape '!' or lower(coalesce(intelligence_knowledge_chunks.heading, '')) like ? escape '!' then 1 else 0 end)";
                $caseBindings[] = $pattern;
                $caseBindings[] = $pattern;
                $matchAny[] = "lower(intelligence_knowledge_chunks.content) like ? escape '!' or lower(coalesce(intelligence_knowledge_chunks.heading, '')) like ? escape '!'";
                $anyBindings[] = $pattern;
                $anyBindings[] = $pattern;
            }
            $query->selectRaw('('.implode(' + ', $cases).') as score', $caseBindings)
                ->whereRaw('('.implode(' or ', $matchAny).')', $anyBindings);
        }

        return $query->orderByDesc('score')->orderBy('intelligence_knowledge_documents.title')->orderBy('intelligence_knowledge_chunks.position')
            ->limit($limit)->get()
            ->map(fn (IntelligenceKnowledgeChunk $row): array => [
                'chunk_id' => (string) $row->chunk_id,
                'document_id' => (string) $row->document_id,
                'title' => (string) $row->title,
                'classification' => (string) $row->classification,
                'position' => (int) $row->position,
                'heading' => $row->heading,
                'content' => (string) $row->content,
                'score' => (float) $row->score,
            ])->all();
    }

    /** @return list<string> distinct question terms of at least MIN_TERM_LENGTH characters that are not stopwords, at most MAX_TERMS */
    public static function terms(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($question)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            $word = trim($word, '-_');
            if (mb_strlen($word) >= self::MIN_TERM_LENGTH && ! in_array($word, self::STOPWORDS, true) && ! in_array($word, $terms, true)) {
                $terms[] = $word;
            }
            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        return $terms;
    }

    /** @return list<string> the distinct required_permission values present on approved documents that the viewer holds */
    private function heldPermissions(User $user): array
    {
        $present = IntelligenceKnowledgeDocument::query()->where('status', 'approved')->whereNotNull('required_permission')
            ->distinct()->limit(200)->pluck('required_permission')->all();

        return array_values(array_filter($present, fn (string $permission): bool => $user->can($permission)));
    }
}
