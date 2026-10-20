<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI;

/**
 * What comes back from the gateway: validated text and/or JSON with its
 * provenance (provider, model, prompt version, run id), or a reason why
 * nothing came back. `text` and `json` are untrusted content: render them as
 * text, never execute or trust them as instructions (brief §60).
 *
 * @param  array<string, mixed>|null  $json
 */
final readonly class AiResult
{
    public const UNAVAILABLE = 'unavailable';

    public const REFUSED = 'refused';

    public const FAILED = 'failed';

    public const INVALID = 'invalid';

    public function __construct(
        public bool $ok,
        public ?string $text = null,
        public ?array $json = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $promptVersion = null,
        public ?string $runId = null,
        public ?string $reason = null,
        public ?string $error = null,
        public int $inputChars = 0,
        public ?int $outputTokens = null,
        public int $durationMs = 0,
    ) {}

    public static function unavailable(string $reason = self::UNAVAILABLE, ?string $error = null): self
    {
        return new self(ok: false, reason: $reason, error: $error);
    }
}
