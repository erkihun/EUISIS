<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\CardRequestStatus;
use App\Enums\CardStatus;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Employee ID cards: the requests waiting, the cards issued and in force,
 * the ones about to expire or needing a reprint, and how often cards are
 * verified at a service point. A card is placed where its holder is placed.
 * Counts only; no card number or holder is named.
 */
final class CredentialsAnalytics extends SituationAnalytics
{
    use ScopesByEmployee;

    public const SOURCE = 'situationRoom.sources.credentials';

    public const EXPIRING_DAYS = 90;

    public function panels(SituationContext $context): array
    {
        return [
            'credentials_kpis' => fn (): SituationPanel => $this->kpis($context),
            'id_card_status' => fn (): SituationPanel => $this->cardStatus($context),
            'card_request_status' => fn (): SituationPanel => $this->requestStatus($context),
            'card_requests_trend' => fn (): SituationPanel => $this->requestsTrend($context),
            'cards_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
        ];
    }

    private function requests(SituationContext $context): Builder
    {
        return DB::table('card_requests as cr')->whereIn('cr.employee_id', $this->employeesInScope($context));
    }

    private function cards(SituationContext $context): Builder
    {
        return DB::table('id_cards as ic')->whereIn('ic.employee_id', $this->employeesInScope($context));
    }

    private function currentCards(SituationContext $context): Builder
    {
        return $this->cards($context)->where('ic.is_current', true);
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $verifications = DB::table('card_verifications as cv')->join('id_cards as ic', 'ic.id', '=', 'cv.id_card_id')->whereIn('ic.employee_id', $this->employeesInScope($context));

        return SituationPanel::kpis('credentials_kpis', $this->title('credentials_kpis'), self::SOURCE, [
            $this->kpi('card_requests_pending', $this->requests($context)->whereIn('cr.status', [CardRequestStatus::Submitted->value, CardRequestStatus::Verified->value])->count()),
            $this->kpi('card_requests_submitted', $this->inPeriod($this->requests($context), 'cr.submitted_at', $period)->count()),
            $this->kpi('cards_issued', $this->inPeriod($this->cards($context), 'ic.issued_at', $period)->count()),
            $this->kpi('cards_active', $this->currentCards($context)->where('ic.status', CardStatus::Active->value)->count()),
            $this->kpi('cards_expiring', $this->currentCards($context)->where('ic.status', CardStatus::Active->value)->whereBetween('ic.expires_at', [now(), now()->addDays(self::EXPIRING_DAYS)])->count()),
            $this->kpi('cards_reprint_required', $this->currentCards($context)->where('ic.reprint_required', true)->count()),
            $this->kpi('card_verifications', $this->inPeriod($verifications, 'cv.created_at', $period)->count()),
            $this->kpi('card_verifications_refused', $this->inPeriod($verifications, 'cv.created_at', $period)->where('cv.allowed', false)->count()),
        ]);
    }

    private function cardStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->currentCards($context), 'ic.status');
        $buckets = array_column(CardStatus::cases(), 'value');

        return $this->chart('id_card_status', 'donut', self::SOURCE, $this->valueLabels('id_card_status', $buckets), [
            ['key' => 'cards', 'label' => SituationLabel::key('situationRoom.series.cards'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function requestStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->requests($context), 'cr.status');
        $buckets = array_values(array_filter(array_column(CardRequestStatus::cases(), 'value'), fn (string $s): bool => $s !== CardRequestStatus::Draft->value));

        return $this->chart('card_request_status', 'bar', self::SOURCE, $this->valueLabels('card_request_status', $buckets), [
            ['key' => 'requests', 'label' => SituationLabel::key('situationRoom.series.requests'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function requestsTrend(SituationContext $context): SituationPanel
    {
        $submitted = $this->trend($this->requests($context), 'cr.submitted_at', $context->period());
        $issued = $this->trend($this->cards($context), 'ic.issued_at', $context->period());

        return $this->chart('card_requests_trend', 'line', self::SOURCE, $submitted['categories'], [
            ['key' => 'card_requests_submitted', 'label' => SituationLabel::key('situationRoom.kpi.card_requests_submitted'), 'values' => $submitted['values']],
            ['key' => 'cards_issued', 'label' => SituationLabel::key('situationRoom.kpi.cards_issued'), 'values' => $issued['values']],
        ], span: 2);
    }

    /** Staff with an active card and staff without one, per roll-up row. */
    private function byOrganization(SituationContext $context): SituationPanel
    {
        $staff = $this->rolledUp($context, WorkforceQueries::headcountByOrganization($context));
        $withCard = $this->joinAssignment(DB::table('id_cards as ic')->where('ic.is_current', true)->where('ic.status', CardStatus::Active->value), 'ic.employee_id', $context);
        $carded = $this->rolledUp($context, $this->countBy($withCard, 'ea.organization_id'));
        $byId = array_combine($carded['ids'], $carded['values']) ?: [];

        return $this->chart('cards_by_organization', 'hbar', self::SOURCE, $staff['categories'], [
            ['key' => 'cards_active', 'label' => SituationLabel::key('situationRoom.kpi.cards_active'), 'values' => $this->suppressSeries(array_map(fn (string $id): int => (int) ($byId[$id] ?? 0), $staff['ids']))],
            ['key' => 'without_card', 'label' => SituationLabel::key('situationRoom.series.without_card'), 'values' => $this->suppressSeries(array_map(fn (string $id, $total): int => max(0, (int) $total - (int) ($byId[$id] ?? 0)), $staff['ids'], $staff['values']))],
        ], span: 2);
    }
}
