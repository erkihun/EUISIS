<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI\Contracts;

/**
 * Text embeddings for semantic retrieval (brief §21). No vector store exists
 * in this installation (NEEDS_INFRASTRUCTURE_VECTOR_STORE), so the bound
 * implementation answers "unavailable" and retrieval uses full-text search.
 */
interface EmbeddingProvider
{
    public function available(): bool;

    public function dimensions(): int;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;
}
