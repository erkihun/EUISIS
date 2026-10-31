<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

/**
 * The public feedback integrity checks, read-only (ADR 0017): the production
 * readiness check and the Intelligence data-quality report list what they
 * find. Repairs stay with the module's own command.
 */
interface FeedbackDataQuality
{
    public const SEVERITY_FAIL = 'FAIL';

    public const SEVERITY_WARN = 'WARN';

    public const SEVERITY_REVIEW = 'NEEDS_DATA_REVIEW';

    public const SEVERITY_INFO = 'INFO';

    /**
     * Every check with its count of affected rows and a few sample ids.
     *
     * @return list<array{key: string, label: string, severity: string, count: int, sample: list<string>, repairable: bool}>
     */
    public function run(): array;
}
