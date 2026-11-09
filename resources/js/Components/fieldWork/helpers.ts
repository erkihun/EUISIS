import type { FieldWorkSummary } from './types';

export { compactInputCls, dangerBtn, fill, inputCls, labelCls, named, panelCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';

/** Display name of a destination in the reader's language. */
export function destinationName(row: Pick<FieldWorkSummary, 'destination'>, locale: string): string {
    const { name_en: en, name_am: am } = row.destination;
    return (locale === 'am' && am ? am : en) ?? '—';
}

export function employeeName(employee: { full_name: string | null; name_en: string | null } | null, locale: string): string {
    if (!employee) return '—';
    return (locale === 'am' ? employee.full_name ?? employee.name_en : employee.name_en ?? employee.full_name) ?? '—';
}
