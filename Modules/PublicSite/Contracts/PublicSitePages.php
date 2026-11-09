<?php

declare(strict_types=1);

namespace Modules\PublicSite\Contracts;

/**
 * The public website's managed wording and links as other parts of the
 * application show them (ADR 0017): the shared header and footer links, and
 * the sections of a page other modules render (the ID checker's verify page).
 * Content is safe-rendered and cached by the module; only Public Site
 * Management changes it.
 */
interface PublicSitePages
{
    /** The ID card verification page. */
    public const PAGE_VERIFY = 'verify';

    /** @return list<array<string, mixed>> */
    public function navigation(): array;

    /**
     * The footer links grouped by footer column.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function footerLinks(): array;

    /**
     * The visible sections of a page, keyed by section key, in display order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sections(string $page): array;

    /** Invalidates every cached public payload (after content changed elsewhere, e.g. a rebrand). */
    public function forgetCache(): void;
}
