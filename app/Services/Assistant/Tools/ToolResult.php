<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

/**
 * What a tool hands back: blocks for the panel (allow-listed types, see
 * docs/assistant/context-and-tools.md), a compact data array the AI narrative
 * may see, plain-text lines for the deterministic answer, and a status.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @param  array<string, mixed>  $data
 * @param  list<string>  $lines
 */
final readonly class ToolResult
{
    public function __construct(
        public string $tool,
        public string $status = 'ok',
        public array $blocks = [],
        public array $data = [],
        public array $lines = [],
        public ?string $dataClass = null,
    ) {}

    public static function denied(string $tool, string $text): self
    {
        return new self($tool, 'denied', [['type' => 'notice', 'tone' => 'warning', 'text' => $text]], [], [$text]);
    }

    public static function empty(string $tool, string $text): self
    {
        return new self($tool, 'empty', [['type' => 'text', 'text' => $text]], [], [$text]);
    }
}
