<?php

declare(strict_types=1);

namespace Modules\Recruitment\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;

/**
 * A position slot advertised in a vacancy announcement, for the Core position
 * establishment page. Writes are refused; the Recruitment module manages
 * announcements.
 */
final class VacancyAnnouncementPositionRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'vacancy_announcement_positions';

    protected function casts(): array
    {
        return ['vacancy_slots' => 'integer'];
    }

    /** The name stored in polymorphic columns, as for the Recruitment module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\VacancyAnnouncementPosition';
    }
}
