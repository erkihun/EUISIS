<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use App\Services\CodeGeneration\SequenceScopeResolver;

/**
 * Hands out a fresh {@see CodeProjector} per import run.
 *
 * A projector accumulates per-scope offsets as it walks a file, so it is
 * single-use by nature — sharing one across two validations would make the
 * second file's codes continue where the first left off. The validator is a
 * long-lived singleton, so it takes this factory rather than a projector.
 */
class CodeProjectorFactory
{
    public function __construct(
        private readonly CodeRuleResolver $codeRuleResolver,
        private readonly CodeGeneratorService $codeGeneratorService,
        private readonly SequenceScopeResolver $sequenceScopeResolver,
    ) {}

    public function make(): CodeProjector
    {
        return new CodeProjector(
            $this->codeRuleResolver,
            $this->codeGeneratorService,
            $this->sequenceScopeResolver,
        );
    }
}
