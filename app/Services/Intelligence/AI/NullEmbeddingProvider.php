<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI;

use App\Services\Intelligence\AI\Contracts\EmbeddingProvider;

/** No vector infrastructure: embeddings are not available and nothing pretends otherwise. */
final class NullEmbeddingProvider implements EmbeddingProvider
{
    public function available(): bool
    {
        return false;
    }

    public function dimensions(): int
    {
        return 0;
    }

    public function embed(array $texts): array
    {
        return [];
    }
}
