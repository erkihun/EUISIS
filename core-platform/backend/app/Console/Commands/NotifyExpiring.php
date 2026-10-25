<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BreakGlassGrant;
use App\Models\CoreAuditLog;
use App\Models\IdCard;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What runs out soon (ADR 0011): an active card expiring within
 * notifications.card_expiry_notice_days, and an emergency access grant ending
 * within notifications.break_glass_warning_minutes. Each is audited once,
 * which is what sends the notification.
 */
class NotifyExpiring extends Command
{
    protected $signature = 'core:notify-expiring';

    protected $description = 'Warn about cards and emergency access that run out soon';

    public function handle(AuditWriter $audit, CoreSettings $settings): int
    {
        SystemHealth::recordRun('core:notify-expiring');
        $days = $settings->int('notifications.card_expiry_notice_days');
        $cards = 0;
        IdCard::query()->with('employee.currentAssignment')->whereIn('status', [IdCard::ACTIVE, IdCard::ISSUED])->where('is_current', true)
            ->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('core_audit_logs')->where('core_audit_logs.action', 'card.expiring')
                ->where('core_audit_logs.subject_type', 'IdCard')->whereRaw('core_audit_logs.subject_id = '.(DB::getDriverName() === 'pgsql' ? 'id_cards.id::text' : 'id_cards.id')) // subject ids are text
                ->where('core_audit_logs.created_at', '>=', now()->subDays($days + 1)))
            ->orderBy('id')->each(function (IdCard $card) use ($audit, &$cards): void {
                $organization = $card->employee?->currentAssignment?->organization_id;
                $audit->write('card.expiring', null, $card, $organization === null ? null : (string) $organization, null, ['expires_at' => $card->expires_at?->toDateString()]);
                $cards++;
            });

        $minutes = $settings->int('notifications.break_glass_warning_minutes');
        $grants = 0;
        BreakGlassGrant::query()->where('status', BreakGlassGrant::ACTIVE)->whereBetween('expires_at', [now(), now()->addMinutes($minutes)])->orderBy('id')
            ->each(function (BreakGlassGrant $grant) use ($audit, &$grants): void {
                if (! CoreAuditLog::query()->where('action', 'break_glass.expiring')->where('subject_id', $grant->id)->exists()) {
                    $audit->write('break_glass.expiring', null, $grant, $grant->organization_id, null, ['expires_at' => $grant->expires_at?->format('Y-m-d H:i')]);
                    $grants++;
                }
            });
        $this->info("{$cards} card(s) and {$grants} emergency access grant(s) announced.");

        return self::SUCCESS;
    }
}
