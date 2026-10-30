<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<AccountabilityEntry> */
class AccountabilityEntryFactory extends Factory
{
    protected $model = AccountabilityEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'sequence' => 1,
            'subject_type' => 'customer',
            'full_name' => 'Customer',
            'role_category' => 'customer',
            'reason_category' => 'malpractice',
            'reason_detail' => 'Offered a payment to skip the queue.',
            'final_action' => 'other',
            'action_detail' => 'Referred to the police.',
            'action_date' => '2026-08-01',
            'source' => 'manual',
        ];
    }
}
