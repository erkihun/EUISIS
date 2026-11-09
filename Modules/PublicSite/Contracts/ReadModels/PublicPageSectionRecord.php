<?php

declare(strict_types=1);

namespace Modules\PublicSite\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;

/**
 * A public page section's stored wording, for the branding migration's
 * report. Writes are refused; Public Site Management (and the
 * PublicSiteRebranding command) change sections.
 */
final class PublicPageSectionRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'public_page_sections';

    /** The name stored in polymorphic columns, as for the Public Site module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\PublicPageSection';
    }
}
