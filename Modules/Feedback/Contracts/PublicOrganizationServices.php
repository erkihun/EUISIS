<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

/**
 * The services an institution offers on its public feedback page (ADR 0017),
 * for the public assistant, which lists the same choices the visitor sees.
 */
interface PublicOrganizationServices
{
    /**
     * What the page gets: the list itself, a search box, or nothing (free text).
     *
     * @return array{mode: 'list'|'search'|'none', items: list<array{id: string, code: string, name: string}>}
     */
    public function serviceOptions(string $organizationId, string $locale): array;
}
