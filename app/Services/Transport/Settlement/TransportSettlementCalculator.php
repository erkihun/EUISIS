<?php

declare(strict_types=1);

namespace App\Services\Transport\Settlement;

use App\Enums\Transport\TransportSettlementModel;

/**
 * Usage evidence → amount, by the contract's settlement model and approved
 * terms. The calculator never invents a rate: a missing term yields no
 * amount and a NEEDS_POLICY_DECISION marker the statement carries. The
 * basis it reads is produced by TransportSettlementService::basis().
 */
final class TransportSettlementCalculator
{
    public const GAP_MODEL = 'NEEDS_POLICY_DECISION_TRANSPORT_SETTLEMENT_MODEL';

    public const GAP_TERM = 'NEEDS_POLICY_DECISION_TRANSPORT_TARIFF';

    /**
     * @param  array<string, mixed>  $snapshot  the contract snapshot (model + terms)
     * @param  array{verified_boardings: int, employee_days: int, completed_trips: int, seat_trips: int}  $basis
     * @return array{amount_cents: int|null, amount: string|null, lines: list<array{label: string, quantity: int|null, rate: string|null, amount: string}>, gaps: list<string>}
     */
    public function calculate(array $snapshot, array $basis): array
    {
        $model = TransportSettlementModel::tryFrom((string) ($snapshot['settlement_model'] ?? ''));
        $terms = is_array($snapshot['terms'] ?? null) ? $snapshot['terms'] : [];

        if ($model === null || $model === TransportSettlementModel::OtherApprovedModel) {
            return $this->undecided([self::GAP_MODEL]);
        }

        $missing = array_values(array_filter($model->requiredTerms(), fn (string $key): bool => ($terms[$key] ?? '') === '' || $terms[$key] === null));
        if ($missing !== []) {
            return $this->undecided(array_map(fn (string $key): string => self::GAP_TERM.':'.$key, $missing));
        }

        $rate = fn (string $key): int => (int) TransportMoney::toCents((string) $terms[$key]);
        $lines = [];
        $total = 0;
        $line = function (string $label, ?int $quantity, ?int $rateCents, int $amount) use (&$lines, &$total): void {
            $lines[] = ['label' => $label, 'quantity' => $quantity, 'rate' => TransportMoney::format($rateCents), 'amount' => (string) TransportMoney::format($amount)];
            $total += $amount;
        };

        switch ($model) {
            case TransportSettlementModel::PerVerifiedBoarding:
                $line('verified_boardings', $basis['verified_boardings'], $rate('rate_per_boarding'), TransportMoney::times($rate('rate_per_boarding'), $basis['verified_boardings']));
                break;
            case TransportSettlementModel::PerEmployeePerDay:
                $line('employee_days', $basis['employee_days'], $rate('rate_per_employee_day'), TransportMoney::times($rate('rate_per_employee_day'), $basis['employee_days']));
                break;
            case TransportSettlementModel::PerCompletedTrip:
                $line('completed_trips', $basis['completed_trips'], $rate('rate_per_trip'), TransportMoney::times($rate('rate_per_trip'), $basis['completed_trips']));
                break;
            case TransportSettlementModel::FixedPeriodic:
                $line('fixed_amount_per_period', 1, $rate('fixed_amount_per_period'), $rate('fixed_amount_per_period'));
                break;
            case TransportSettlementModel::FixedPlusUsage:
                $line('fixed_amount_per_period', 1, $rate('fixed_amount_per_period'), $rate('fixed_amount_per_period'));
                $line('verified_boardings', $basis['verified_boardings'], $rate('rate_per_boarding'), TransportMoney::times($rate('rate_per_boarding'), $basis['verified_boardings']));
                break;
            case TransportSettlementModel::MinimumGuaranteePlusUsage:
                $usage = TransportMoney::times($rate('rate_per_boarding'), $basis['verified_boardings']);
                $minimum = $rate('minimum_guarantee_amount');
                $line('verified_boardings', $basis['verified_boardings'], $rate('rate_per_boarding'), $usage);
                if ($usage < $minimum) {
                    $line('minimum_guarantee_top_up', 1, null, $minimum - $usage);
                }
                break;
            case TransportSettlementModel::CapacityBased:
                $line('seat_trips', $basis['seat_trips'], $rate('rate_per_seat_trip'), TransportMoney::times($rate('rate_per_seat_trip'), $basis['seat_trips']));
                break;
            case TransportSettlementModel::OtherApprovedModel:
                break;
        }

        return ['amount_cents' => $total, 'amount' => (string) TransportMoney::format($total), 'lines' => $lines, 'gaps' => []];
    }

    /** @param  list<string>  $gaps @return array{amount_cents: null, amount: null, lines: list<never>, gaps: list<string>} */
    private function undecided(array $gaps): array
    {
        return ['amount_cents' => null, 'amount' => null, 'lines' => [], 'gaps' => $gaps];
    }
}
