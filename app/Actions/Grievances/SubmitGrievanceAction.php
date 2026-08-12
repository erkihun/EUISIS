<?php

declare(strict_types=1);

namespace App\Actions\Grievances;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\User;
use Illuminate\Support\Str;

readonly class SubmitGrievanceAction
{
    public function __construct(private WriteAuditLogAction $audit) {}

    /**
     * @param  array{subject: string, description: string, category_id: string, origin_level: string, organization_id: string, organization_unit_id?: string|null, employee_id?: string|null, metadata?: array|null}  $data
     */
    public function execute(User $actor, array $data): Grievance
    {
        $grievance = Grievance::query()->create([
            'reference_number' => $this->generateReference(),
            'submitted_by_user_id' => $actor->id,
            'employee_id' => $data['employee_id'] ?? $actor->employee_id ?? null,
            'organization_id' => $data['organization_id'],
            'organization_unit_id' => $data['organization_unit_id'] ?? null,
            'origin_level' => $data['origin_level'],
            'category_id' => $data['category_id'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status' => GrievanceStatus::Submitted,
            'submitted_at' => now(),
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->audit->execute(
            eventType: AuditEventType::GrievanceSubmitted,
            actor: $actor,
            auditable: $grievance,
            organizationId: $grievance->organization_id,
            newValues: ['reference_number' => $grievance->reference_number, 'status' => $grievance->status->value],
        );

        return $grievance;
    }

    private function generateReference(): string
    {
        return 'GRV-'.now()->format('Y').'-'.strtoupper(Str::random(6));
    }
}
