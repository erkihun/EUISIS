<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI;

/**
 * One request to the gateway. `input` is the compact evidence bundle or the
 * retrieved passages the prompt works on; the gateway redacts and truncates
 * it, the prompt wraps it as DATA, never as instructions (brief §61).
 *
 * `dataClasses` names what the input contains (IntelligenceSettings::DATA_CLASSES);
 * the gateway refuses a class the settings do not allow outbound. `schema`
 * describes the JSON the prompt asks for; the gateway validates against it
 * and fails safely on a malformed answer (brief §118).
 *
 * @param  array<string, mixed>|null  $schema
 * @param  list<string>  $dataClasses
 * @param  array<string, string|int|float>  $variables  Prompt placeholders other than the input.
 */
final readonly class AiRequest
{
    public function __construct(
        public string $feature,
        public string $promptKey,
        public string $input,
        public array $dataClasses,
        public ?array $schema = null,
        public array $variables = [],
        public ?int $triggeredByUserId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $locale = null,
    ) {}
}
