<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use App\Services\Vacancy\PositionCapacityService;
use Illuminate\Support\Facades\DB;

/**
 * Identity invariants the application enforces with locks and validation but
 * that no database constraint backs (MySQL/PostgreSQL cannot express "one
 * current row per employee" portably), so older data or a past race may break
 * them. Read-only.
 */
class AuditDuplicateData extends ReadOnlyAuditCommand
{
    protected $signature = 'data:audit-duplicates {--json : Also print a machine-readable summary} {--strict : Exit 1 on any finding, not only HIGH}';

    protected $description = 'Read-only: report duplicate employees, assignments, occupants, cards and scopes';

    private const LIVE_CARD = ['pending_print', 'printed', 'issued', 'active'];

    protected function checks(): array
    {
        return [
            [
                'key' => 'employees.national_id_hash', 'severity' => 'HIGH',
                'label' => 'Employees sharing a national ID',
                'why' => 'Validation refuses a second employee with the same national ID; no unique index backs it. Rows: employee ids per shared hash.',
                'rows' => fn () => DB::table('employees')->whereNotNull('national_id_hash')
                    ->select('national_id_hash')->groupBy('national_id_hash')->havingRaw('count(*) > 1')->pluck('national_id_hash')
                    ->map(fn ($hash) => DB::table('employees')->where('national_id_hash', $hash)->pluck('id')->implode(', ')),
            ],
            [
                'key' => 'employee_assignments.current', 'severity' => 'HIGH',
                'label' => 'Employees with more than one current assignment',
                'why' => 'An employee has one current placement; two make organization scope, cards and cafeteria policy ambiguous.',
                'rows' => fn () => DB::table('employee_assignments')->where('is_current', true)
                    ->select('employee_id')->groupBy('employee_id')->havingRaw('count(*) > 1')->pluck('employee_id'),
            ],
            [
                'key' => 'employees.current_assignment_id', 'severity' => 'MEDIUM',
                'label' => 'Employees whose current_assignment_id is not their current assignment',
                'why' => 'The pointer must name a current assignment of the same employee.',
                'rows' => fn () => DB::table('employees as e')->join('employee_assignments as a', 'a.id', '=', 'e.current_assignment_id')
                    ->where(fn ($q) => $q->whereColumn('a.employee_id', '!=', 'e.id')->orWhere('a.is_current', false))
                    ->pluck('e.id'),
            ],
            [
                'key' => 'positions.occupants', 'severity' => 'HIGH',
                'label' => 'Positions with more current occupants than approved slots',
                'why' => 'Registration allows one occupant per position; an approved establishment may allow more. Rows: position (occupants / slots).',
                'rows' => fn () => DB::table('employee_assignments')->where('is_current', true)->where('assignment_status', 'active')
                    ->whereNotNull('position_id')->select('position_id', DB::raw('count(*) as occupants'))->groupBy('position_id')->havingRaw('count(*) > 1')->get()
                    ->map(fn ($row) => [$row, max(1, app(PositionCapacityService::class)->approvedSlotsForPosition($row->position_id))])
                    ->filter(fn (array $pair) => $pair[0]->occupants > $pair[1])
                    ->map(fn (array $pair) => "{$pair[0]->position_id} ({$pair[0]->occupants} / {$pair[1]})")->values(),
            ],
            [
                'key' => 'id_cards.live', 'severity' => 'HIGH',
                'label' => 'Employees holding more than one live card',
                'why' => 'Only one card may be pending print, printed, issued or active; two are two valid credentials.',
                'rows' => fn () => DB::table('id_cards')->whereIn('status', self::LIVE_CARD)
                    ->select('employee_id')->groupBy('employee_id')->havingRaw('count(*) > 1')->pluck('employee_id'),
            ],
            [
                'key' => 'id_cards.is_current', 'severity' => 'HIGH',
                'label' => 'Employees with more than one current card',
                'why' => 'is_current marks the single card a scan or print should use.',
                'rows' => fn () => DB::table('id_cards')->where('is_current', true)
                    ->select('employee_id')->groupBy('employee_id')->havingRaw('count(*) > 1')->pluck('employee_id'),
            ],
            [
                'key' => 'card_requests.pending', 'severity' => 'MEDIUM',
                'label' => 'Employees with more than one open card request',
                'why' => 'A new request is refused while one is draft, submitted or verified.',
                'rows' => fn () => DB::table('card_requests')->whereIn('status', ['draft', 'submitted', 'verified'])
                    ->select('employee_id')->groupBy('employee_id')->havingRaw('count(*) > 1')->pluck('employee_id'),
            ],
            [
                'key' => 'users.email_case', 'severity' => 'MEDIUM',
                'label' => 'User e-mails that differ only by letter case',
                'why' => 'The unique index is case-sensitive on PostgreSQL; login and reset treat these as one address.',
                'rows' => fn () => DB::table('users')->selectRaw('lower(email) as address')->groupByRaw('lower(email)')
                    ->havingRaw('count(*) > 1')->pluck('address')->map(fn ($address) => DB::table('users')->whereRaw('lower(email) = ?', [$address])->pluck('id')->implode(', ')),
            ],
            [
                'key' => 'user_organization_scopes.duplicate', 'severity' => 'LOW',
                'label' => 'Duplicate active organization scope grants',
                'why' => 'Harmless to access checks, but they double-count in reviews of who can see what.',
                'rows' => fn () => DB::table('user_organization_scopes')->where('is_active', true)
                    ->select('user_id', 'organization_id', 'scope_type')->groupBy('user_id', 'organization_id', 'scope_type')
                    ->havingRaw('count(*) > 1')->get()->map(fn ($row) => "user {$row->user_id} → {$row->organization_id} ({$row->scope_type})"),
            ],
        ];
    }
}
