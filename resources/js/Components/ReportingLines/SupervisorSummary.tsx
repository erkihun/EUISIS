import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { badgeClassName, cx } from '@/Components/ui';
import type { SupervisorResolution } from './types';

/**
 * One resolution step: the person (or the reason there is none), the position
 * they hold, and the rule that put them there. Used by the employee page, the
 * chain and My Portal; the employee link is shown only when the caller may
 * open employee records.
 */
export default function SupervisorSummary({ resolution, employeeHref, compact = false }: {
    resolution: SupervisorResolution;
    employeeHref?: string | null;
    compact?: boolean;
}) {
    const { t, locale } = useLocale();
    const tone = resolution.is_resolved ? 'success' : resolution.is_legitimate_end ? 'info' : 'warning';
    const name = locale === 'am' ? (resolution.supervisor_name_am ?? resolution.supervisor_name) : resolution.supervisor_name;
    const position = localizedName(resolution.supervisor_position_title_en ?? '', resolution.supervisor_position_title_am, locale);
    const unit = resolution.supervisor_unit_name_en ? localizedName(resolution.supervisor_unit_name_en, resolution.supervisor_unit_name_am, locale) : null;

    return (
        <div className={cx('rounded-card border border-(--app-border) bg-(--app-surface)', compact ? 'p-3' : 'p-4')}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    {resolution.is_resolved ? (
                        <>
                            <div className="text-sm font-semibold text-(--app-foreground)">
                                {employeeHref ? (
                                    <Link href={employeeHref} className="text-primary hover:underline">{name}</Link>
                                ) : name}
                                {resolution.supervisor_employee_number && (
                                    <span className="ml-2 text-xs font-normal text-(--app-muted-foreground)">{resolution.supervisor_employee_number}</span>
                                )}
                            </div>
                            <div className="mt-0.5 text-xs text-(--app-muted-foreground)">
                                {position}{unit ? ` · ${unit}` : ''}
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="text-sm font-semibold text-(--app-foreground)">{resolution.outcome_label}</div>
                            {resolution.supervisor_position_id && (
                                <div className="mt-0.5 text-xs text-(--app-muted-foreground)">{position}{unit ? ` · ${unit}` : ''}</div>
                            )}
                        </>
                    )}
                </div>
                <div className="flex flex-wrap gap-1">
                    {resolution.is_resolved && <span className={badgeClassName(tone)}>{resolution.outcome_label}</span>}
                    {!resolution.is_resolved && <span className={badgeClassName(tone)}>{resolution.is_legitimate_end ? t('reportingLines.topLabel') : t('reportingLines.problemLabel')}</span>}
                    {resolution.basis_label && <span className={badgeClassName('neutral')}>{resolution.basis_label}</span>}
                    {resolution.occupant_basis === 'acting' && <span className={badgeClassName('info')}>{resolution.occupant_basis_label}</span>}
                </div>
            </div>
            {!compact && (
                <p className="mt-2 text-xs text-(--app-muted-foreground)">
                    <span className="font-medium text-(--app-foreground)">{t('reportingLines.explanation')}: </span>{resolution.explanation_text}
                </p>
            )}
        </div>
    );
}
