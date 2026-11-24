<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

/**
 * Requests a generated file. The request is a row written in the current
 * transaction; generation happens afterwards on the queue and is retried from
 * the row if the queue message is lost. The caller never waits for it.
 */
interface ArtifactQueue
{
    /**
     * @param  string  $ownerType  submission or consolidation
     * @param  string  $label  draft, submitted or approved: printed on the document
     * @return string the artifact id
     */
    public function request(string $ownerType, string $ownerId, string $label, string $format = 'docx'): string;
}
