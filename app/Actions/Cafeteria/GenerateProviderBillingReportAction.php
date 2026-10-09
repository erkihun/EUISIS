<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaProvider;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

readonly class GenerateProviderBillingReportAction
{
    public function __construct(
        private CafeteriaBillingService $billingService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    /**
     * @return array{provider_id: string, period_start: string, period_end: string, transaction_count: int, total_meal_amount: float, total_subsidy_covered: float, total_employee_payable: float, total_carry_forward_deductions: float, government_payable: float}
     */
    public function execute(CafeteriaProvider $provider, Carbon $from, Carbon $to, User $actor, ?Request $request = null): array
    {
        $billing = $this->billingService->getProviderBilling($provider, $from, $to);

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaReportGenerated,
            $actor,
            $provider,
            $provider->organization_id,
            newValues: [
                'provider_code'    => $provider->code,
                'period_start'     => $from->toDateString(),
                'period_end'       => $to->toDateString(),
                'government_payable' => $billing['government_payable'],
            ],
            request: $request,
        );

        return $billing;
    }
}
