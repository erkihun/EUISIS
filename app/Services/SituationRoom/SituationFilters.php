<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Illuminate\Http\Request;
use Modules\Feedback\Contracts\Data\PublicFeedbackTargetType;
use Modules\Feedback\Contracts\Data\PublicTipType;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;

/**
 * The filters a Situation Room view accepts, cleaned. Unknown keys are
 * dropped, malformed values are dropped, and ids are only shape-checked here:
 * every query re-applies the viewer's scope, so a forged id can narrow a view
 * but never reach outside it.
 */
final readonly class SituationFilters
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        public SituationPeriod $period,
        public array $values,
    ) {}

    public static function fromRequest(Request $request, string $defaultPeriod = 'month'): self
    {
        $period = SituationPeriod::fromInput(
            self::string($request->query('period')),
            self::string($request->query('from')),
            self::string($request->query('to')),
            $defaultPeriod,
        );

        $values = [];
        foreach (['organization_id', 'organization_unit_id', 'position_service_id'] as $key) {
            $value = self::string($request->query($key));
            if ($value !== null && preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1) {
                $values[$key] = strtolower($value);
            }
        }
        $target = self::string($request->query('target_type'));
        if ($target !== null && in_array($target, array_column(PublicFeedbackTargetType::cases(), 'value'), true)) {
            $values['target_type'] = $target;
        }
        $status = self::string($request->query('status'));
        if ($status !== null && in_array($status, ServiceFeedbackStatus::values(), true)) {
            $values['status'] = $status;
        }
        $tip = self::string($request->query('tip_type'));
        if ($tip !== null && in_array($tip, PublicTipType::values(), true)) {
            $values['tip_type'] = $tip;
        }

        $page = self::string($request->query('page'));
        if ($page !== null && preg_match('/^[1-9][0-9]{0,3}$/', $page) === 1 && $page !== '1') {
            $values['page'] = $page;
        }

        return new self($period, $values);
    }

    /**
     * The filters as URL query parameters, for links that keep the view's
     * period and narrowing (never the page number).
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $query = ['period' => $this->period->preset];
        if ($this->period->preset === 'custom') {
            $query['from'] = $this->period->from->toDateString();
            $query['to'] = $this->period->to->toDateString();
        }

        return $query + array_diff_key($this->values, ['page' => true]);
    }

    public function page(): int
    {
        return (int) ($this->values['page'] ?? 1);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function fingerprint(): string
    {
        $values = $this->values;
        ksort($values);

        return hash('sha256', json_encode([$this->period->toArray(), $values], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['period' => $this->period->toArray()] + $this->values;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
