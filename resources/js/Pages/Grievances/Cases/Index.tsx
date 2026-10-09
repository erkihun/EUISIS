import { useLocale } from '@/hooks/useLocale';
import { Workspace, CaseTable, ActionForm, TabLinks } from '@/Components/grievances/Workspace';
import { nameOf, useEnumLabel } from '@/Components/grievances/ui';
import type { CasesIndexProps } from '@/types/grievances';

export default function Index({ grievances, scope, filters, options, can }: CasesIndexProps) {
    const { t, locale } = useLocale(); const label = useEnumLabel();
    return <Workspace title={t(`grievanceCases.${scope}`)}><TabLinks items={['assigned', ...(can.authorized ? ['authorized'] : []), ...(can.intake ? ['intake'] : [])].map(v => ({ label: t(`grievanceCases.${v}`), href: route('grievances.cases.index', { tab: v }), active: scope === v }))} />
        <ActionForm method="get" url={route('grievances.cases.index')} initial={{ ...filters, tab: scope }} submitLabel={t('grievances.common.filter')} fields={[
            { name: 'search', label: t('grievances.common.search') },
            { name: 'status', label: t('grievances.common.status'), type: 'select', options: options.statuses.map(v => ({ value: v, label: label('status', v) })) },
            { name: 'category_id', label: t('grievances.common.category'), type: 'select', options: options.categories.map(v => ({ value: v.id!, label: nameOf(v, locale) })) },
            { name: 'organization_id', label: t('grievances.common.organization'), type: 'select', options: options.organizations.map(v => ({ value: v.id!, label: nameOf(v, locale) })) },
            { name: 'sla', label: t('grievances.common.sla'), type: 'select', options: options.slaStates.map(v => ({ value: v, label: label('sla_state', v) })) },
            { name: 'confidentiality', label: t('grievances.common.confidentiality'), type: 'select', options: options.confidentiality.map(v => ({ value: v, label: label('confidentiality', v) })) },
            { name: 'from', label: t('grievances.common.from'), type: 'date' }, { name: 'to', label: t('grievances.common.to'), type: 'date' },
        ]} /><CaseTable page={grievances} /></Workspace>;
}
