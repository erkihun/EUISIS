<?php

declare(strict_types=1);

namespace Modules\Recruitment\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Recruitment\Contracts\Data\VacancyAnnouncementStatus;

/**
 * A vacancy announcement, for the Core transfer record that a vacancy filled.
 * Writes are refused; the Recruitment module manages announcements.
 */
final class VacancyAnnouncementRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $table = 'vacancy_announcements';

    protected function casts(): array
    {
        return [
            'status' => VacancyAnnouncementStatus::class,
            'application_opens_at' => 'datetime',
            'application_closes_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'eligibility_rules' => 'array',
        ];
    }

    /** The name stored in polymorphic columns, as for the Recruitment module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\VacancyAnnouncement';
    }
}
