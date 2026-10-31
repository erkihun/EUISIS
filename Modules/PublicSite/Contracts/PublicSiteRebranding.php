<?php

declare(strict_types=1);

namespace Modules\PublicSite\Contracts;

/**
 * The owner command the branding migration uses on public-site content
 * (ADR 0017, docs/branding.md §6). It reads sections through
 * ReadModels\PublicPageSectionRecord and changes only the home intro titles.
 */
interface PublicSiteRebranding
{
    /**
     * Sets the home page intro's titles; only `title_en` and `title_am` are
     * accepted. Returns false when the site has no home intro section.
     *
     * @param  array<'title_en'|'title_am', string>  $titles
     */
    public function setHomeIntroTitles(array $titles): bool;
}
