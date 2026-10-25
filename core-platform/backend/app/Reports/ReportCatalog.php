<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\OrganizationEdge;
use App\Models\User;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Core reports (ADR 0011). Each report names the permission it needs
 * besides reports.view, whether it is city-wide only, the filters it takes,
 * its columns (English, Amharic, sensitive) and its query. Every query is
 * limited to the reader's organizations here, so the screen preview and the
 * export of a report can never differ in what they reach.
 *
 * Sensitive columns (phone, e-mail, date of birth) leave only in an export
 * asked for with reports.export_sensitive and a stated purpose; a national ID
 * is never in any report.
 */
final class ReportCatalog
{
    public const FILTERS = ['organization', 'subtree', 'employee_status', 'position_status', 'assignment_status', 'card_status', 'from', 'to', 'as_of',
        'hierarchy_version', 'job_grade', 'occupation', 'profession', 'severity', 'action'];

    public function __construct(private readonly OrganizationScope $scope) {}

    /** @return array<string, array{permission: string, citywide: bool, filters: list<string>, columns: array<string, array{0: string, 1: string, 2?: bool}>, values?: array<string, string>}> */
    public static function definitions(): array
    {
        $org = ['organization', 'subtree'];

        return [
            'organizations' => ['permission' => 'organizations.view', 'citywide' => false, 'filters' => [...$org, 'hierarchy_version'],
                'columns' => ['code' => ['Code', 'ኮድ'], 'name_en' => ['Name (English)', 'ስም (እንግሊዝኛ)'], 'name_am' => ['Name (Amharic)', 'ስም (አማርኛ)'],
                    'type' => ['Type', 'ዓይነት'], 'status' => ['Status', 'ሁኔታ'], 'parent' => ['Parent', 'የበላይ'], 'effective_from' => ['In force from', 'ከ የሚጸና']],
                'values' => ['status' => 'organization_status'], 'dates' => ['effective_from']],
            'employees' => ['permission' => 'employees.view', 'citywide' => false, 'filters' => [...$org, 'employee_status', 'job_grade', 'occupation', 'profession'],
                'columns' => ['employee_number' => ['Employee number', 'የሠራተኛ ቁጥር'], 'full_name' => ['Full name', 'ሙሉ ስም'], 'status' => ['Status', 'ሁኔታ'],
                    'verification_status' => ['Verification', 'ማረጋገጫ'], 'organization' => ['Organization', 'ተቋም'], 'unit' => ['Unit', 'የሥራ ክፍል'],
                    'position' => ['Position', 'የሥራ መደብ'], 'grade' => ['Grade', 'ደረጃ'], 'gender' => ['Gender', 'ጾታ'],
                    'phone' => ['Phone', 'ስልክ', true], 'email' => ['E-mail', 'ኢሜይል', true], 'date_of_birth' => ['Date of birth', 'የትውልድ ቀን', true]],
                'values' => ['status' => 'employee_status', 'verification_status' => 'employee_status'], 'dates' => ['date_of_birth']],
            'positions' => ['permission' => 'positions.viewAny', 'citywide' => false, 'filters' => [...$org, 'position_status', 'job_grade', 'occupation', 'profession'],
                'columns' => ['code' => ['Code', 'ኮድ'], 'title' => ['Title', 'መጠሪያ'], 'organization' => ['Organization', 'ተቋም'], 'unit' => ['Unit', 'የሥራ ክፍል'],
                    'status' => ['Status', 'ሁኔታ'], 'grade' => ['Grade', 'ደረጃ'], 'occupation' => ['Occupation', 'የሙያ መስክ'], 'profession' => ['Profession', 'ሙያ'],
                    'holders' => ['Held', 'የተያዙ']],
                'values' => ['status' => 'position_status']],
            'vacancies' => ['permission' => 'positions.viewAny', 'citywide' => false, 'filters' => [...$org, 'job_grade', 'occupation'],
                'columns' => ['code' => ['Code', 'ኮድ'], 'title' => ['Title', 'መጠሪያ'], 'organization' => ['Organization', 'ተቋም'], 'grade' => ['Grade', 'ደረጃ'],
                    'seats' => ['Seats', 'መቀመጫዎች'], 'holders' => ['Held', 'የተያዙ'], 'vacant' => ['Vacant', 'ክፍት']]],
            'assignments' => ['permission' => 'assignments.view', 'citywide' => false, 'filters' => [...$org, 'assignment_status', 'from', 'to'],
                'columns' => ['employee_number' => ['Employee number', 'የሠራተኛ ቁጥር'], 'full_name' => ['Full name', 'ሙሉ ስም'], 'organization' => ['Organization', 'ተቋም'],
                    'unit' => ['Unit', 'የሥራ ክፍል'], 'position' => ['Position', 'የሥራ መደብ'], 'assignment_type' => ['How', 'እንዴት'],
                    'effective_from' => ['From', 'ከ'], 'status' => ['Status', 'ሁኔታ']],
                'values' => ['status' => 'assignment_status', 'assignment_type' => 'assignment_type'], 'dates' => ['effective_from']],
            'assignment_history' => ['permission' => 'assignments.view', 'citywide' => false, 'filters' => [...$org, 'from', 'to', 'as_of'],
                'columns' => ['employee_number' => ['Employee number', 'የሠራተኛ ቁጥር'], 'full_name' => ['Full name', 'ሙሉ ስም'], 'organization' => ['Organization (then)', 'ተቋም (በወቅቱ)'],
                    'position' => ['Position (then)', 'የሥራ መደብ (በወቅቱ)'], 'assignment_type' => ['How', 'እንዴት'], 'reason_code' => ['Reason', 'ምክንያት'],
                    'effective_from' => ['From', 'ከ'], 'effective_to' => ['To', 'እስከ'], 'status' => ['Status', 'ሁኔታ'], 'source_system' => ['Recorded through', 'የተመዘገበበት']],
                'values' => ['status' => 'assignment_status', 'assignment_type' => 'assignment_type'], 'dates' => ['effective_from', 'effective_to']],
            'hierarchy' => ['permission' => 'hierarchy-versions.viewAny', 'citywide' => false, 'filters' => [...$org, 'hierarchy_version'],
                'columns' => ['version' => ['Hierarchy version', 'የተዋረድ ስሪት'], 'parent_code' => ['Parent code', 'የበላይ ኮድ'], 'parent' => ['Parent', 'የበላይ'],
                    'child_code' => ['Code', 'ኮድ'], 'child' => ['Organization', 'ተቋም'], 'relationship' => ['Relationship', 'ግንኙነት'], 'effective_from' => ['From', 'ከ']],
                'dates' => ['effective_from']],
            'hierarchy_changes' => ['permission' => 'hierarchy-versions.viewAny', 'citywide' => false, 'filters' => ['from', 'to'],
                'columns' => ['version' => ['Version', 'ስሪት'], 'status' => ['Status', 'ሁኔታ'], 'legal_basis' => ['Legal basis', 'የሕግ መሠረት'],
                    'effective_from' => ['In force from', 'ከ የሚጸና'], 'reviewed_at' => ['Reviewed', 'የተገመገመበት'], 'approval_date' => ['Approved', 'የጸደቀበት'],
                    'edges' => ['Relationships', 'ግንኙነቶች']],
                'values' => ['status' => 'hierarchy_status'], 'dates' => ['effective_from', 'reviewed_at', 'approval_date']],
            'card_status' => ['permission' => 'cards.view', 'citywide' => false, 'filters' => [...$org, 'card_status', 'from', 'to'],
                'columns' => ['card_number' => ['Card number', 'የካርድ ቁጥር'], 'employee_number' => ['Employee number', 'የሠራተኛ ቁጥር'], 'full_name' => ['Full name', 'ሙሉ ስም'],
                    'organization' => ['Organization', 'ተቋም'], 'status' => ['Status', 'ሁኔታ'], 'qc_status' => ['Quality check', 'የጥራት ፍተሻ'],
                    'print_count' => ['Times printed', 'የታተመበት ብዛት'], 'issued_at' => ['Issued', 'የተሰጠበት'], 'expires_at' => ['Expires', 'የሚያበቃበት']],
                'values' => ['status' => 'card_status', 'qc_status' => 'qc_status'], 'dates' => ['issued_at', 'expires_at']],
            'card_coverage' => ['permission' => 'cards.view', 'citywide' => false, 'filters' => $org,
                'columns' => ['code' => ['Code', 'ኮድ'], 'organization' => ['Organization', 'ተቋም'], 'active_employees' => ['Active employees', 'ንቁ ሠራተኞች'],
                    'with_card' => ['With a live card', 'ሕያው ካርድ ያላቸው'], 'coverage' => ['Coverage %', 'ሽፋን %']]],
            'expiring_cards' => ['permission' => 'cards.view', 'citywide' => false, 'filters' => [...$org, 'to'],
                'columns' => ['card_number' => ['Card number', 'የካርድ ቁጥር'], 'employee_number' => ['Employee number', 'የሠራተኛ ቁጥር'], 'full_name' => ['Full name', 'ሙሉ ስም'],
                    'organization' => ['Organization', 'ተቋም'], 'status' => ['Status', 'ሁኔታ'], 'expires_at' => ['Expires', 'የሚያበቃበት']],
                'values' => ['status' => 'card_status'], 'dates' => ['expires_at']],
            'lost_damaged_replaced' => ['permission' => 'cards.view', 'citywide' => false, 'filters' => [...$org, 'from', 'to'],
                'columns' => ['card_number' => ['Card number', 'የካርድ ቁጥር'], 'employee_number' => ['Employee number', 'የሠራተኛ ቁጥር'], 'full_name' => ['Full name', 'ሙሉ ስም'],
                    'organization' => ['Organization', 'ተቋም'], 'status' => ['Status', 'ሁኔታ'], 'replacement' => ['Replaced by', 'የተተካው በ'], 'updated_at' => ['Changed', 'የተቀየረበት']],
                'values' => ['status' => 'card_status'], 'dates' => ['updated_at']],
            'credential_status' => ['permission' => 'digital_credentials.view', 'citywide' => false, 'filters' => [...$org, 'card_status'],
                'columns' => ['card_number' => ['Card number', 'የካርድ ቁጥር'], 'employee_number' => ['Employee number', 'የሠራተኛ ቁጥር'], 'organization' => ['Organization', 'ተቋም'],
                    'status' => ['Credential', 'የማረጋገጫ መለያ'], 'kind' => ['Kind', 'ዓይነት'], 'key_version' => ['Key', 'ቁልፍ'], 'issued_at' => ['Issued', 'የተሰጠበት'],
                    'last_verified_at' => ['Last checked', 'የመጨረሻ የተፈተሸበት'], 'verification_count' => ['Checks', 'ፍተሻዎች']],
                'values' => ['status' => 'credential_status'], 'dates' => ['issued_at', 'last_verified_at']],
            'data_quality' => ['permission' => 'data_quality.view', 'citywide' => false, 'filters' => [...$org, 'severity', 'from', 'to'],
                'columns' => ['check_code' => ['Check', 'ፍተሻ'], 'severity' => ['Severity', 'ክብደት'], 'status' => ['Status', 'ሁኔታ'], 'entity_type' => ['Record', 'መዝገብ'],
                    'entity_id' => ['Record id', 'የመዝገብ መለያ'], 'organization' => ['Organization', 'ተቋም'], 'detected_at' => ['Found', 'የተገኘበት'], 'resolved_at' => ['Closed', 'የተዘጋበት']],
                'values' => ['severity' => 'severity', 'status' => 'issue_status'], 'dates' => ['detected_at', 'resolved_at']],
            'user_access' => ['permission' => 'users.viewAny', 'citywide' => true, 'filters' => [],
                'columns' => ['name' => ['Name', 'ስም'], 'email' => ['E-mail', 'ኢሜይል', true], 'role' => ['Role', 'ሚና'], 'scope_type' => ['Scope', 'ወሰን'],
                    'scope_organization' => ['Scope organization', 'የወሰን ተቋም'], 'is_active' => ['Active', 'ንቁ'], 'mfa' => ['Two-step sign-in', 'ባለሁለት ደረጃ መግቢያ'],
                    'last_login_at' => ['Last sign-in', 'የመጨረሻ መግቢያ']],
                'dates' => ['last_login_at']],
            'organization_scope' => ['permission' => 'users.viewAny', 'citywide' => true, 'filters' => ['organization'],
                'columns' => ['organization' => ['Organization', 'ተቋም'], 'name' => ['Account', 'መለያ'], 'role' => ['Role', 'ሚና'], 'scope_type' => ['Scope', 'ወሰን'], 'is_active' => ['Active', 'ንቁ']]],
            'audit' => ['permission' => 'audit.view', 'citywide' => false, 'filters' => ['organization', 'subtree', 'from', 'to', 'action'],
                'columns' => ['created_at' => ['When', 'መቼ'], 'action' => ['Action', 'ተግባር'], 'subject_type' => ['Record type', 'የመዝገብ ዓይነት'], 'subject_id' => ['Record', 'መዝገብ'],
                    'actor' => ['By', 'በ'], 'organization' => ['Organization', 'ተቋም'], 'reason' => ['Reason', 'ምክንያት'], 'source' => ['Through', 'በ(መንገድ)']],
                'dates' => ['created_at'], 'datetime' => true],
            'api_client_usage' => ['permission' => 'integrations.view', 'citywide' => true, 'filters' => ['from', 'to'],
                'columns' => ['client' => ['Application', 'መተግበሪያ'], 'calls' => ['Calls', 'ጥሪዎች'], 'errors' => ['Errors', 'ስህተቶች'], 'denials' => ['Refused', 'የተከለከሉ'],
                    'mean_ms' => ['Mean time (ms)', 'አማካይ ጊዜ (ሚሴ)'], 'last_call' => ['Last call', 'የመጨረሻ ጥሪ']],
                'dates' => ['last_call'], 'datetime' => true],
            'webhook_deliveries' => ['permission' => 'integrations.view', 'citywide' => true, 'filters' => ['from', 'to'],
                'columns' => ['endpoint' => ['Webhook', 'ዌብሁክ'], 'event_type' => ['Event', 'ክስተት'], 'status' => ['Status', 'ሁኔታ'], 'attempts' => ['Attempts', 'ሙከራዎች'],
                    'response_code' => ['Answer', 'ምላሽ'], 'created_at' => ['Raised', 'የተነሳበት'], 'delivered_at' => ['Delivered', 'የደረሰበት']],
                'dates' => ['created_at', 'delivered_at'], 'datetime' => true],
            'security_events' => ['permission' => 'security_dashboard.view', 'citywide' => true, 'filters' => ['from', 'to', 'action'],
                'columns' => ['created_at' => ['When', 'መቼ'], 'action' => ['Event', 'ክስተት'], 'actor' => ['By', 'በ'], 'subject_type' => ['Record type', 'የመዝገብ ዓይነት'],
                    'subject_id' => ['Record', 'መዝገብ'], 'request_ip' => ['From address', 'ከአድራሻ'], 'reason' => ['Reason', 'ምክንያት']],
                'dates' => ['created_at'], 'datetime' => true],
        ];
    }

    /** The security-relevant audit actions (security_events report and the security dashboard). */
    public const SECURITY_ACTIONS = ['auth.%', 'mfa.%', 'user.%', 'role.%', 'break_glass.%', 'api_client.%', 'credential.%', 'webhook.suspended', 'webhook.secret_rotated',
        'audit.%', 'system_settings.%', 'settings.%'];

    /** @return list<string> the reports this person may open */
    public function available(User $user): array
    {
        if (! $user->can('reports.view')) {
            return [];
        }

        return array_keys(array_filter(self::definitions(), fn (array $d): bool => $user->can($d['permission']) && (! $d['citywide'] || $this->scope->organizationIds($user) === null)));
    }

    /**
     * The report's rows for this person, limited to their organizations.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(string $report, User $user, array $filters): Builder
    {
        abort_unless(in_array($report, $this->available($user), true), 403);
        $ids = $this->organizations($user, $filters);
        $limit = fn (Builder $q, string $column) => $ids === null ? $q : $q->whereIn($column, $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids);
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $version = isset($filters['hierarchy_version']) ? HierarchyVersion::query()->find($filters['hierarchy_version']) : HierarchyVersion::current();

        return match ($report) {
            'organizations' => $limit(DB::table('organizations')
                ->leftJoin('organization_types', 'organization_types.id', '=', 'organizations.organization_type_id')
                ->leftJoin('organization_edges as pe', fn ($j) => $j->on('pe.child_organization_id', '=', 'organizations.id')
                    ->where('pe.hierarchy_version_id', $version?->id)->where('pe.relationship_type', OrganizationEdge::STRUCTURAL))
                ->leftJoin('organizations as parent', 'parent.id', '=', 'pe.parent_organization_id')
                ->whereNull('organizations.deleted_at')
                ->select(['organizations.code', 'organizations.name_en', 'organizations.name_am', 'organization_types.name_en as type', 'organizations.status',
                    'parent.name_en as parent', 'organizations.effective_from', 'organizations.id as _key'])->orderBy('organizations.code'), 'organizations.id'),
            'employees' => $limit(DB::table('employees')
                ->leftJoin('employee_assignments as a', 'a.id', '=', 'employees.current_assignment_id')
                ->leftJoin('organizations', 'organizations.id', '=', 'a.organization_id')
                ->leftJoin('organization_units', 'organization_units.id', '=', 'a.organization_unit_id')
                ->leftJoin('positions', 'positions.id', '=', 'a.position_id')
                ->leftJoin('job_grades', 'job_grades.id', '=', 'positions.job_grade_id')
                ->when($filters['employee_status'] ?? null, fn ($q, $v) => $q->where('employees.status', $v))
                ->when($filters['job_grade'] ?? null, fn ($q, $v) => $q->where('positions.job_grade_id', $v))
                ->when($filters['occupation'] ?? null, fn ($q, $v) => $q->where('positions.occupation_id', $v))
                ->when($filters['profession'] ?? null, fn ($q, $v) => $q->where('positions.profession_id', $v))
                ->select(['employees.employee_number', 'employees.full_name', 'employees.status', 'employees.verification_status', 'organizations.name_en as organization',
                    'organization_units.name_en as unit', 'positions.title_en as position', 'job_grades.code as grade', 'employees.gender',
                    'employees.phone', 'employees.email', 'employees.date_of_birth', 'employees.id as _key'])->orderBy('employees.employee_number'), 'a.organization_id'),
            'positions', 'vacancies' => $this->positions($report, $limit, $filters),
            'assignments' => $limit(DB::table('employee_assignments as a')
                ->join('employees', 'employees.id', '=', 'a.employee_id')
                ->leftJoin('organizations', 'organizations.id', '=', 'a.organization_id')
                ->leftJoin('organization_units', 'organization_units.id', '=', 'a.organization_unit_id')
                ->leftJoin('positions', 'positions.id', '=', 'a.position_id')
                ->where('a.is_current', true)
                ->when($filters['assignment_status'] ?? null, fn ($q, $v) => $q->where('a.assignment_status', $v))
                ->when($from, fn ($q, $v) => $q->where('a.effective_from', '>=', $v))->when($to, fn ($q, $v) => $q->where('a.effective_from', '<=', $v))
                ->select(['employees.employee_number', 'employees.full_name', 'organizations.name_en as organization', 'organization_units.name_en as unit',
                    'positions.title_en as position', 'a.assignment_type', 'a.effective_from', 'a.assignment_status as status', 'a.id as _key'])
                ->orderBy('employees.employee_number'), 'a.organization_id'),
            // The organization and position of the time: the placement's own, never today's structure.
            'assignment_history' => $limit(DB::table('employee_assignments as a')
                ->join('employees', 'employees.id', '=', 'a.employee_id')
                ->leftJoin('organizations', 'organizations.id', '=', 'a.organization_id')
                ->leftJoin('positions', 'positions.id', '=', 'a.position_id')
                ->when($filters['as_of'] ?? null, fn ($q, $v) => $q->where('a.effective_from', '<=', $v)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $v))
                    ->where('a.assignment_status', '!=', EmployeeAssignment::PENDING))
                ->when($from, fn ($q, $v) => $q->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $v)))
                ->when($to, fn ($q, $v) => $q->where('a.effective_from', '<=', $v))
                ->select(['employees.employee_number', 'employees.full_name', 'organizations.name_en as organization', 'positions.title_en as position', 'a.assignment_type',
                    'a.reason_code', 'a.effective_from', 'a.effective_to', 'a.assignment_status as status', 'a.source_system', 'a.id as _key'])
                ->orderBy('employees.employee_number')->orderBy('a.effective_from'), 'a.organization_id'),
            'hierarchy' => $limit(DB::table('organization_edges as e')
                ->join('hierarchy_versions as v', 'v.id', '=', 'e.hierarchy_version_id')
                ->join('organizations as p', 'p.id', '=', 'e.parent_organization_id')
                ->join('organizations as c', 'c.id', '=', 'e.child_organization_id')
                ->where('e.hierarchy_version_id', $version?->id)
                ->select(['v.version_name as version', 'p.code as parent_code', 'p.name_en as parent', 'c.code as child_code', 'c.name_en as child',
                    'e.relationship_type as relationship', 'e.effective_from', 'e.id as _key'])->orderBy('p.code')->orderBy('c.code'), 'e.child_organization_id'),
            'hierarchy_changes' => DB::table('hierarchy_versions as v')
                ->when($from, fn ($q, $v) => $q->whereDate('v.created_at', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('v.created_at', '<=', $v))
                ->select(['v.version_name as version', 'v.status', 'v.legal_basis', 'v.effective_from', 'v.reviewed_at', 'v.approval_date',
                    DB::raw('(select count(*) from organization_edges where organization_edges.hierarchy_version_id = v.id) as edges'), 'v.id as _key'])
                ->orderByDesc('v.created_at'),
            'card_status', 'expiring_cards', 'lost_damaged_replaced' => $this->cards($report, $limit, $filters),
            'card_coverage' => $limit(DB::table('organizations')
                ->whereNull('organizations.deleted_at')
                ->select(['organizations.code', 'organizations.name_en as organization',
                    DB::raw("(select count(*) from employees join employee_assignments a on a.id = employees.current_assignment_id where a.organization_id = organizations.id and employees.status = 'active' and a.assignment_status = 'active') as active_employees"),
                    DB::raw("(select count(*) from employees join employee_assignments a on a.id = employees.current_assignment_id where a.organization_id = organizations.id and employees.status = 'active' and a.assignment_status = 'active' and exists (select 1 from id_cards where id_cards.employee_id = employees.id and id_cards.is_current = ".$this->true()." and id_cards.status in ('pending_print','printed','issued','active'))) as with_card"),
                    DB::raw('null as coverage'), 'organizations.id as _key'])->orderBy('organizations.code'), 'organizations.id'),
            'credential_status' => $limit(DB::table('digital_credentials as d')
                ->join('id_cards', 'id_cards.id', '=', 'd.id_card_id')
                ->join('employees', 'employees.id', '=', 'd.employee_id')
                ->leftJoin('employee_assignments as a', 'a.id', '=', 'employees.current_assignment_id')
                ->leftJoin('organizations', 'organizations.id', '=', 'a.organization_id')
                ->when($filters['card_status'] ?? null, fn ($q, $v) => $q->where('id_cards.status', $v))
                ->select(['id_cards.card_number', 'employees.employee_number', 'organizations.name_en as organization', 'd.status', 'd.kind', 'd.key_version',
                    'd.issued_at', 'd.last_verified_at', 'd.verification_count', 'd.id as _key'])->orderBy('id_cards.card_number')->orderBy('d.issued_at'), 'a.organization_id'),
            'data_quality' => $limit(DB::table('data_quality_issues as i')
                ->leftJoin('organizations', 'organizations.id', '=', 'i.organization_id')
                ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('i.severity', $v))
                ->when($from, fn ($q, $v) => $q->whereDate('i.detected_at', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('i.detected_at', '<=', $v))
                ->select(['i.check_code', 'i.severity', 'i.status', 'i.entity_type', 'i.entity_id', 'organizations.name_en as organization', 'i.detected_at',
                    DB::raw('coalesce(i.resolved_at, i.accepted_risk_at) as resolved_at'), 'i.id as _key'])->orderByDesc('i.detected_at'), 'i.organization_id'),
            'user_access' => DB::table('users')->leftJoin('organizations', 'organizations.id', '=', 'users.scope_organization_id')
                ->select(['users.name', 'users.email', 'users.role', 'users.scope_type', 'organizations.name_en as scope_organization', 'users.is_active',
                    'users.two_factor_enabled as mfa', 'users.last_login_at', 'users.id as _key'])->orderBy('users.name'),
            'organization_scope' => DB::table('users')->join('organizations', 'organizations.id', '=', 'users.scope_organization_id')
                ->when($filters['organization'] ?? null, fn ($q, $v) => $q->where('users.scope_organization_id', $v))
                ->select(['organizations.name_en as organization', 'users.name', 'users.role', 'users.scope_type', 'users.is_active', 'users.id as _key'])
                ->orderBy('organizations.name_en')->orderBy('users.name'),
            'audit' => $limit(DB::table('core_audit_logs as l')
                ->leftJoin('users', 'users.id', '=', 'l.actor_user_id')
                ->leftJoin('organizations', 'organizations.id', '=', 'l.organization_id')
                ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('l.action', 'like', str_replace('*', '%', $v)))
                ->when($from, fn ($q, $v) => $q->where('l.created_at', '>=', $v))->when($to, fn ($q, $v) => $q->where('l.created_at', '<', date('Y-m-d', strtotime($v.' +1 day'))))
                ->select(['l.created_at', 'l.action', 'l.subject_type', 'l.subject_id', 'users.name as actor', 'organizations.name_en as organization', 'l.reason', 'l.source', 'l.id as _key'])
                ->orderByDesc('l.created_at')->orderByDesc('l.id'), 'l.organization_id'),
            'api_client_usage' => DB::table('api_clients as c')
                ->leftJoin('api_request_logs as r', fn ($j) => $j->on('r.api_client_id', '=', 'c.id')
                    ->where('r.created_at', '>=', $from ?? now()->subDays(30)->toDateString())->where('r.created_at', '<', $to ? date('Y-m-d', strtotime($to.' +1 day')) : now()->addDay()->toDateString()))
                ->groupBy('c.id', 'c.name')
                ->select(['c.name as client', DB::raw('count(r.id) as calls'), DB::raw('sum(case when r.status >= 400 then 1 else 0 end) as errors'),
                    DB::raw('sum(case when r.denial_code is not null then 1 else 0 end) as denials'), DB::raw('avg(r.duration_ms) as mean_ms'), DB::raw('max(r.created_at) as last_call'), 'c.id as _key'])
                ->orderBy('c.name'),
            'webhook_deliveries' => DB::table('webhook_deliveries as d')
                ->join('webhook_endpoints as w', 'w.id', '=', 'd.webhook_endpoint_id')
                ->join('outbox_events as e', 'e.id', '=', 'd.outbox_event_id')
                ->when($from, fn ($q, $v) => $q->where('d.created_at', '>=', $v))->when($to, fn ($q, $v) => $q->where('d.created_at', '<', date('Y-m-d', strtotime($v.' +1 day'))))
                ->select(['w.name as endpoint', 'e.event_type', 'd.status', 'd.attempts', 'd.response_code', 'd.created_at', 'd.delivered_at', 'd.id as _key'])
                ->orderByDesc('d.created_at')->orderByDesc('d.id'),
            'security_events' => DB::table('core_audit_logs as l')
                ->leftJoin('users', 'users.id', '=', 'l.actor_user_id')
                ->where(fn ($q) => collect(self::SECURITY_ACTIONS)->each(fn (string $a) => $q->orWhere('l.action', 'like', $a)))
                ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('l.action', 'like', str_replace('*', '%', $v)))
                ->when($from, fn ($q, $v) => $q->where('l.created_at', '>=', $v))->when($to, fn ($q, $v) => $q->where('l.created_at', '<', date('Y-m-d', strtotime($v.' +1 day'))))
                ->select(['l.created_at', 'l.action', 'users.name as actor', 'l.subject_type', 'l.subject_id', 'l.request_ip', 'l.reason', 'l.id as _key'])
                ->orderByDesc('l.created_at')->orderByDesc('l.id'),
            default => abort(404),
        };
    }

    /**
     * The organizations the report covers: the reader's scope, narrowed to a chosen
     * organization (and everything under it when "subtree" is asked for).
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>|null null = the whole city
     */
    private function organizations(User $user, array $filters): ?array
    {
        $ids = $this->scope->organizationIds($user);
        $chosen = $filters['organization'] ?? null;
        if ($chosen === null || $chosen === '') {
            return $ids;
        }
        $wanted = ! empty($filters['subtree']) ? $this->scope->subtree((string) $chosen) : [(string) $chosen];

        return $ids === null ? $wanted : array_values(array_intersect($wanted, $ids));
    }

    /** @param array<string, mixed> $filters */
    private function positions(string $report, \Closure $limit, array $filters): Builder
    {
        $today = now()->toDateString();
        $holders = '(select count(*) from employee_assignments h where h.position_id = positions.id and h.is_current = '.$this->true()." and h.assignment_status = 'active')";
        $seats = "(select coalesce(sum(approved_slots), 0) from position_establishments s where s.position_id = positions.id and s.status = 'approved' and s.deleted_at is null and s.effective_from <= '{$today}' and (s.effective_to is null or s.effective_to >= '{$today}'))";
        $query = DB::table('positions')
            ->leftJoin('organizations', 'organizations.id', '=', 'positions.organization_id')
            ->leftJoin('organization_units', 'organization_units.id', '=', 'positions.organization_unit_id')
            ->leftJoin('job_grades', 'job_grades.id', '=', 'positions.job_grade_id')
            ->leftJoin('occupations', 'occupations.id', '=', 'positions.occupation_id')
            ->leftJoin('professions', 'professions.id', '=', 'positions.profession_id')
            ->whereNull('positions.deleted_at')
            ->when($filters['position_status'] ?? null, fn ($q, $v) => $q->where('positions.lifecycle_status', $v))
            ->when($filters['job_grade'] ?? null, fn ($q, $v) => $q->where('positions.job_grade_id', $v))
            ->when($filters['occupation'] ?? null, fn ($q, $v) => $q->where('positions.occupation_id', $v))
            ->when($filters['profession'] ?? null, fn ($q, $v) => $q->where('positions.profession_id', $v));
        if ($report === 'vacancies') {
            $default = (int) app(CoreSettings::class)->int('workforce.default_seat_capacity');
            $capacity = "(case when {$seats} > 0 then {$seats} else {$default} end)";
            $query->where('positions.lifecycle_status', 'approved')->whereRaw("{$capacity} > {$holders}")
                ->select(['positions.job_position_code as code', 'positions.title_en as title', 'organizations.name_en as organization', 'job_grades.code as grade',
                    DB::raw("{$capacity} as seats"), DB::raw("{$holders} as holders"), DB::raw("{$capacity} - {$holders} as vacant"), 'positions.id as _key']);
        } else {
            $query->select(['positions.job_position_code as code', 'positions.title_en as title', 'organizations.name_en as organization', 'organization_units.name_en as unit',
                'positions.lifecycle_status as status', 'job_grades.code as grade', 'occupations.name_en as occupation', 'professions.name_en as profession',
                DB::raw("{$holders} as holders"), 'positions.id as _key']);
        }

        return $limit($query->orderBy('positions.job_position_code'), 'positions.organization_id');
    }

    /** @param array<string, mixed> $filters */
    private function cards(string $report, \Closure $limit, array $filters): Builder
    {
        $query = DB::table('id_cards')
            ->join('employees', 'employees.id', '=', 'id_cards.employee_id')
            ->leftJoin('employee_assignments as a', 'a.id', '=', 'employees.current_assignment_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'a.organization_id');
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        match ($report) {
            'card_status' => $query->when($filters['card_status'] ?? null, fn ($q, $v) => $q->where('id_cards.status', $v))
                ->when($from, fn ($q, $v) => $q->whereDate('id_cards.created_at', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('id_cards.created_at', '<=', $v))
                ->select(['id_cards.card_number', 'employees.employee_number', 'employees.full_name', 'organizations.name_en as organization', 'id_cards.status',
                    'id_cards.qc_status', 'id_cards.print_count', 'id_cards.issued_at', 'id_cards.expires_at', 'id_cards.id as _key']),
            'expiring_cards' => $query->whereIn('id_cards.status', [IdCard::ACTIVE, IdCard::ISSUED])->where('id_cards.is_current', true)
                ->whereNotNull('id_cards.expires_at')->where('id_cards.expires_at', '<=', $to ?? now()->addDays(30)->toDateString())
                ->select(['id_cards.card_number', 'employees.employee_number', 'employees.full_name', 'organizations.name_en as organization', 'id_cards.status',
                    'id_cards.expires_at', 'id_cards.id as _key']),
            'lost_damaged_replaced' => $query->whereIn('id_cards.status', [IdCard::LOST, IdCard::DAMAGED, IdCard::REPLACED])
                ->leftJoin('id_cards as r', 'r.previous_card_id', '=', 'id_cards.id')
                ->when($from, fn ($q, $v) => $q->whereDate('id_cards.updated_at', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('id_cards.updated_at', '<=', $v))
                ->select(['id_cards.card_number', 'employees.employee_number', 'employees.full_name', 'organizations.name_en as organization', 'id_cards.status',
                    'r.card_number as replacement', 'id_cards.updated_at', 'id_cards.id as _key']),
        };

        return $limit($query->orderBy('id_cards.card_number'), 'a.organization_id');
    }

    private function true(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'true' : '1';
    }
}
