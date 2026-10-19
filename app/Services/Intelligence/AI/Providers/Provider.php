<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI\Providers;

/** One model provider driver: configured from the environment only, called with a system and a user message. */
interface Provider
{
    public function configured(): bool;

    public function model(): ?string;

    /**
     * @return array{text: string, output_tokens: ?int}
     *
     * @throws \RuntimeException on transport or provider errors
     */
    public function chat(string $system, string $user, int $maxOutputTokens, int $timeoutSeconds): array;
}
