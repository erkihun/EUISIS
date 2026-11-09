<?php

declare(strict_types=1);

namespace Modules\Recruitment\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\EstablishmentStatus;
use App\Models\PositionEstablishment;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Recruitment\Contracts\Data\VacancyAnnouncementStatus;
use Modules\Recruitment\Domain\Models\VacancyAnnouncement;
use Modules\Recruitment\Domain\Models\VacancyAnnouncementPosition;

readonly class PublishVacancyAnnouncementAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function createDraft(array $data, User $actor): VacancyAnnouncement
    {
        $positions = $data['positions'] ?? [];

        foreach ($positions as $p) {
            $est = PositionEstablishment::findOrFail($p['position_establishment_id']);
            if ($est->status !== EstablishmentStatus::Approved) {
                throw ValidationException::withMessages([
                    'positions' => __('vacancies.establishmentNotApproved'),
                ]);
            }
        }

        $announcement = VacancyAnnouncement::create([
            'announcement_number' => $this->generateNumber(),
            'title_en' => $data['title_en'],
            'title_am' => $data['title_am'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'description_am' => $data['description_am'] ?? null,
            'application_opens_at' => $data['application_opens_at'] ?? null,
            'application_closes_at' => $data['application_closes_at'] ?? null,
            'status' => VacancyAnnouncementStatus::Draft->value,
            'eligibility_rules' => $data['eligibility_rules'] ?? null,
        ]);

        foreach ($positions as $p) {
            $est = PositionEstablishment::find($p['position_establishment_id']);
            VacancyAnnouncementPosition::create([
                'vacancy_announcement_id' => $announcement->id,
                'position_establishment_id' => $est->id,
                'organization_id' => $est->organization_id,
                'organization_unit_id' => $est->organization_unit_id,
                'position_id' => $est->position_id,
                'vacancy_slots' => $p['vacancy_slots'],
            ]);
        }

        $this->writeAuditLogAction->execute(
            AuditEventType::VacancyAnnouncementCreated,
            $actor,
            $announcement,
            null,
            newValues: $announcement->toArray(),
        );

        return $announcement;
    }

    public function publish(VacancyAnnouncement $announcement, User $actor): VacancyAnnouncement
    {
        if ($announcement->status !== VacancyAnnouncementStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('vacancies.notDraft'),
            ]);
        }

        $hasAvailableSlots = $announcement->positions()->get()->some(fn ($p) => $p->availableSlots() > 0);

        if (! $hasAvailableSlots) {
            throw ValidationException::withMessages([
                'slots' => __('vacancies.noSlotsAvailable'),
            ]);
        }

        $old = $announcement->toArray();

        $announcement->update([
            'status' => VacancyAnnouncementStatus::Published->value,
            'published_by' => $actor->id,
            'published_at' => now(),
        ]);

        $fresh = $announcement->fresh();

        $this->writeAuditLogAction->execute(
            AuditEventType::VacancyAnnouncementPublished,
            $actor,
            $fresh,
            null,
            oldValues: $old,
            newValues: $fresh->toArray(),
        );

        return $fresh;
    }

    /** From the vacancy-announcement code rule (VCY-YYYYMM-NNNN by default). */
    private function generateNumber(): string
    {
        return app(GenerateCodeAction::class)->execute(CodeRuleEntityType::VacancyAnnouncement, [], null, null, 'announcement_number');
    }
}
