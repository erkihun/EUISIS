<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaDayRule;
use App\Models\User;
use Illuminate\Http\Request;

readonly class UpdateCafeteriaDayRuleAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaDayRule $rule, array $attributes, User $actor, ?Request $request = null): void
    {
        $old = $rule->only(['is_open', 'is_subsidy_day', 'open_time', 'close_time', 'notes']);

        $rule->update([
            'is_open'        => (bool) ($attributes['is_open'] ?? $rule->is_open),
            'is_subsidy_day' => (bool) ($attributes['is_subsidy_day'] ?? $rule->is_subsidy_day),
            'open_time'      => $attributes['open_time'] ?? $rule->open_time,
            'close_time'     => $attributes['close_time'] ?? $rule->close_time,
            'notes'          => $attributes['notes'] ?? $rule->notes,
            'updated_by'     => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaDayRuleUpdated,
            $actor,
            $rule,
            null,
            oldValues: $old,
            newValues: $rule->only(['is_open', 'is_subsidy_day', 'open_time', 'close_time']),
            request: $request,
        );
    }
}
