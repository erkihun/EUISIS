import { ListPage } from '../components/ListPage';
import { StatusBadge } from '../components/ui';
import { useI18n } from '../i18n';
import { navigate } from '../router';
import type { Assignment, AuditEntry, Employee, Organization, Position, Unit } from '../types';

// The read-only list screens. Each is a column set over one scoped API list.

export function Organizations() {
    const { t, lang } = useI18n();

    return (
        <ListPage<Organization>
            title={t('nav.organizations')}
            endpoint="/v1/organizations"
            columns={[
                { label: t('common.code'), render: (row) => <span className="font-mono text-xs">{row.code}</span> },
                { label: t('common.name'), render: (row) => (lang === 'am' && row.name_am) || row.name_en },
                { label: t('common.type'), render: (row) => (lang === 'am' && row.type?.name_am) || row.type?.name_en || '—' },
                { label: t('common.status'), render: (row) => <StatusBadge value={row.status} /> },
            ]}
        />
    );
}

export function Units() {
    const { t, lang } = useI18n();

    return (
        <ListPage<Unit>
            title={t('nav.units')}
            endpoint="/v1/organization-units"
            columns={[
                { label: t('common.code'), render: (row) => <span className="font-mono text-xs">{row.code}</span> },
                { label: t('common.name'), render: (row) => (lang === 'am' && row.name_am) || row.name_en },
                { label: t('common.organization'), render: (row) => row.organization?.name_en ?? '—' },
                { label: t('common.status'), render: (row) => <StatusBadge value={row.status} /> },
            ]}
        />
    );
}

export function Positions() {
    const { t, lang } = useI18n();

    return (
        <ListPage<Position>
            title={t('nav.positions')}
            endpoint="/v1/positions"
            filters={[
                {
                    name: 'occupancy',
                    label: t('pos.occupancy'),
                    options: [
                        { value: 'vacant', label: t('pos.vacant') },
                        { value: 'held', label: t('pos.held') },
                    ],
                },
            ]}
            columns={[
                { label: t('common.code'), render: (row) => <span className="font-mono text-xs">{row.job_position_code}</span> },
                { label: t('common.name'), render: (row) => (lang === 'am' && row.title_am) || row.title_en },
                { label: t('common.organization'), render: (row) => row.organization?.name_en ?? '—' },
                { label: t('pos.holders'), render: (row) => row.holders_count, className: 'tabular-nums' },
                { label: t('common.status'), render: (row) => <StatusBadge value={row.is_active ? 'active' : 'inactive'} /> },
            ]}
        />
    );
}

export function Employees() {
    const { t } = useI18n();

    return (
        <ListPage<Employee>
            title={t('nav.employees')}
            endpoint="/v1/employees"
            onRowClick={(row) => navigate(`/employees/${row.id}`)}
            columns={[
                { label: t('emp.number'), render: (row) => <span className="font-mono text-xs">{row.employee_number}</span> },
                { label: t('emp.fullName'), render: (row) => row.full_name },
                { label: t('common.position'), render: (row) => row.current_assignment?.position?.title_en ?? '—' },
                { label: t('common.organization'), render: (row) => row.current_assignment?.organization?.name_en ?? '—' },
                { label: t('emp.hasCard'), render: (row) => (row.has_active_card ? t('common.yes') : t('common.no')) },
                { label: t('common.status'), render: (row) => <StatusBadge value={row.status} /> },
            ]}
        />
    );
}

export function Assignments() {
    const { t, date } = useI18n();

    return (
        <ListPage<Assignment>
            title={t('nav.assignments')}
            endpoint="/v1/assignments"
            toggles={[{ name: 'history', label: t('asg.history') }]}
            onRowClick={(row) => navigate(`/employees/${row.employee_id}`)}
            columns={[
                { label: t('emp.number'), render: (row) => <span className="font-mono text-xs">{row.employee?.employee_number}</span> },
                { label: t('emp.fullName'), render: (row) => row.employee?.full_name ?? '—' },
                { label: t('common.position'), render: (row) => row.position?.title_en ?? '—' },
                { label: t('common.organization'), render: (row) => row.organization?.name_en ?? '—' },
                { label: t('common.from'), render: (row) => date(row.effective_from) },
                { label: t('common.to'), render: (row) => date(row.effective_to) },
                { label: t('common.status'), render: (row) => <StatusBadge value={row.assignment_status} /> },
            ]}
        />
    );
}

export function Audit() {
    const { t } = useI18n();

    return (
        <ListPage<AuditEntry>
            title={t('nav.audit')}
            endpoint="/v1/audit"
            searchable={false}
            filters={[
                {
                    name: 'action',
                    label: t('audit.action'),
                    options: ['employee.registered', 'assignment.transferred', 'card.suspend', 'card.reinstate', 'card.revoke'].map((value) => ({ value, label: value })),
                },
            ]}
            columns={[
                { label: t('audit.when'), render: (row) => row.created_at.replace('T', ' ').slice(0, 19) },
                { label: t('audit.action'), render: (row) => <span className="font-mono text-xs">{row.action}</span> },
                { label: t('audit.subject'), render: (row) => `${row.subject_type.split('\\').pop()} ${row.subject_id.slice(0, 8)}` },
                { label: t('common.reason'), render: (row) => row.reason ?? '—' },
                { label: t('audit.request'), render: (row) => <span className="font-mono text-xs">{row.request_id?.slice(0, 12) ?? '—'}</span> },
            ]}
        />
    );
}
