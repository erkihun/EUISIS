<?php

declare(strict_types=1);

namespace Modules\PublicSite\Application\Queries;

use InvalidArgumentException;
use Modules\PublicSite\Application\Services\PublicSiteContent;
use Modules\PublicSite\Contracts\PublicSiteRebranding;
use Modules\PublicSite\Domain\Models\PublicPageSection;

final class PublicSiteRebrandingCommand implements PublicSiteRebranding
{
    public function setHomeIntroTitles(array $titles): bool
    {
        if (array_diff(array_keys($titles), ['title_en', 'title_am']) !== []) {
            throw new InvalidArgumentException('Only the home intro titles may be set.');
        }
        $intro = PublicPageSection::query()->where('page', 'home')->where('section_key', 'intro')->first();
        if ($intro === null) {
            return false;
        }
        $intro->forceFill($titles)->save();
        PublicSiteContent::flush();

        return true;
    }
}
