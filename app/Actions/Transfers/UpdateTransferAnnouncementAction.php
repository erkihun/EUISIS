<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EstablishmentStatus;
use App\Enums\TransferAnnouncementStatus;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\TransferAnnouncement;
use App\Models\TransferAnnouncementPosition;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class UpdateTransferAnnouncementAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private OrganizationScopeService $scopeService,
    ) {}

    public function execute(TransferAnnouncement $announcement, array $data, User $actor): TransferAnnouncement
    {
        if ($announcement->status !== TransferAnnouncementStatus::Draft) {
            throw new DomainException(__('transfers.announcementNotDraft'));
        }

        DB::transaction(function () use ($announcement, $data, $actor): void {
            $announcement = TransferAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($announcement->status !== TransferAnnouncementStatus::Draft) {
                throw new DomainException(__('transfers.announcementNotDraft'));
            }
            $positions = $data['positions'] ?? [];
            $seen = [];
            foreach ($positions as $posData) {
                $positionId = $posData['position_id'];
                $organizationId = $posData['organization_id'];
                if (isset($seen[$positionId]) || ! $this->scopeService->canAccessOrganization($actor, $organizationId)) {
                    throw new DomainException('The requested position is duplicated or outside your organization scope.');
                }
                $seen[$positionId] = true;

                $position = Position::query()->lockForUpdate()->find($positionId);
                $available = PositionEstablishment::query()
                    ->where('organization_id', $organizationId)
                    ->where('position_id', $positionId)
                    ->where('status', EstablishmentStatus::Approved->value)
                    ->lockForUpdate()
                    ->get()
                    ->sum(fn (PositionEstablishment $establishment): int => $establishment->availableSlots());
                if ($position === null || $position->organization_id !== $organizationId || ! $position->isSelectable() || $available < (int) $posData['vacancy_count']) {
                    throw new DomainException('The requested position is no longer available with the requested capacity.');
                }
            }
            $firstPos = $positions[0] ?? null;
            $totalVacancies = $positions
                ? array_sum(array_column($positions, 'vacancy_count'))
                : ($announcement->number_of_vacancies ?? 1);

            $announcement->update([
                'organization_id' => $firstPos['organization_id'] ?? $announcement->organization_id,
                'position_id' => $firstPos['position_id'] ?? $announcement->position_id,
                'grade_level' => $firstPos['grade_level'] ?? null,
                'salary_min' => $firstPos['salary_min'] ?? null,
                'salary_max' => $firstPos['salary_max'] ?? null,
                'number_of_vacancies' => $totalVacancies,
                'eligibility_rules' => $data['eligibility_rules'] ?? null,
                'required_documents' => $data['required_documents'] ?? null,
                'opening_date' => $data['opening_date'] ?? $announcement->opening_date,
                'closing_date' => $data['closing_date'] ?? $announcement->closing_date,
            ]);

            if (! empty($positions)) {
                $announcement->positions()->delete();
                foreach ($positions as $posData) {
                    TransferAnnouncementPosition::query()->create([
                        'transfer_announcement_id' => $announcement->id,
                        'organization_id' => $posData['organization_id'],
                        'position_id' => $posData['position_id'],
                        'grade_level' => $posData['grade_level'] ?? null,
                        'salary_min' => $posData['salary_min'] ?? null,
                        'salary_max' => $posData['salary_max'] ?? null,
                        'vacancy_count' => (int) ($posData['vacancy_count'] ?? 1),
                    ]);
                }
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::TransferAnnouncementUpdated,
                $actor,
                $announcement->fresh(),
                $announcement->organization_id,
                newValues: array_intersect_key($data, array_flip([
                    'opening_date', 'closing_date', 'eligibility_rules', 'required_documents',
                ])),
            );
        });

        return $announcement->fresh();
    }
}
