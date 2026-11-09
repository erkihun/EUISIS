<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

/**
 * What a public tool hands back: allow-listed blocks for the panel, the
 * compact public data the AI narrative may see, plain lines for the
 * deterministic answer.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @param  array<string, mixed>  $data
 * @param  list<string>  $lines
 */
final readonly class PublicToolResult
{
    public function __construct(
        public string $tool,
        public string $status = 'ok',
        public array $blocks = [],
        public array $data = [],
        public array $lines = [],
    ) {}

    public static function text(string $tool, string $text, string $status = 'ok'): self
    {
        return new self($tool, $status, [['type' => 'text', 'text' => $text]], [], [$text]);
    }
}
