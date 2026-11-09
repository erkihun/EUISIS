import { useLocale } from '@/hooks/useLocale';
import { badgeClassName, type Tone } from '@/Components/ui';

/*
 * Shared pieces of the Organization Design & Workforce Planning pages
 * (docs/organization-design-workforce-planning-plan.md §7).
 */

export type Bilingual = { name_en: string; name_am?: string | null } | null | undefined;
export type BilingualTitle = { title_en: string; title_am?: string | null } | null | undefined;

export function nameOf(value: Bilingual, locale: string): string {
    if (!value) return '';
    return (locale === 'am' && value.name_am) || value.name_en || '';
}

export function titleOf(value: BilingualTitle, locale: string): string {
    if (!value) return '';
    return (locale === 'am' && value.title_am) || value.title_en || '';
}

const STATUS_TONES: Record<string, Tone> = {
    draft: 'neutral', under_review: 'info', approved: 'info', published: 'success', superseded: 'warning', cancelled: 'danger',
    active: 'success', inactive: 'neutral',
};

/** A design-record status in the viewer's language. */
export function StatusPill({ status }: { status: string }) {
    const { t } = useLocale();
    const key = `organizationDesign.status.${status}`;
    const label = t(key);
    return <span className={badgeClassName(STATUS_TONES[status] ?? 'neutral')}>{label === key ? status : label}</span>;
}

/** Translator bound to the organizationDesign namespace. */
export function useDesignText() {
    const { t, locale } = useLocale();
    return { locale, t, tt: (key: string) => t(`organizationDesign.${key}`) };
}
