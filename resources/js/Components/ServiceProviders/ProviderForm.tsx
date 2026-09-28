import { useLocale } from '@/hooks/useLocale';
import { Button, FieldError, Input, Select, buttonClassName, cx } from '@euisis/ui';
import type { InertiaFormProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import type { FormEvent, JSX, ReactNode } from 'react';
import {
    DemoTag,
    PROVIDER_STATUSES,
    ProviderAvatar,
    ProviderStatusBadge,
    StatusDot,
    initials,
    providerStatusKey,
    typeTint,
    useLocalName,
} from './providerUi';

export type ProviderFormData = {
    name: string;
    code: string;
    service_type_id: string;
    organization_id: string;
    status: string;
    is_demo: boolean;
};

type Option = { id: string; code?: string; name_en: string; name_am?: string | null };

type Props = {
    form: InertiaFormProps<ProviderFormData>;
    serviceTypes: Option[];
    organizations: Option[];
    submitLabel: string;
    cancelHref: string;
    onSubmit: () => void;
};

const STATUS_HINTS: Record<string, string> = {
    active: 'providers.statusActiveHint',
    inactive: 'providers.statusInactiveHint',
    suspended: 'providers.statusSuspendedHint',
};

/** Shared by Create and Edit: the same fields, a live preview of the list row beside them. */
export default function ProviderForm({ form, serviceTypes, organizations, submitLabel, cancelHref, onSubmit }: Props): JSX.Element {
    const { t } = useLocale();
    const localName = useLocalName();
    const { data, setData, errors, processing } = form;

    const selectedType = serviceTypes.find((type) => type.id === data.service_type_id);

    function submit(event: FormEvent) {
        event.preventDefault();
        onSubmit();
    }

    return (
        <div className="grid items-start gap-5 lg:grid-cols-12">
            <form onSubmit={submit} noValidate className="rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] lg:col-span-8">
                <Section title={t('providers.sectionIdentity')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="name" label={t('providers.name')} required error={errors.name}>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                placeholder={t('providers.namePlaceholder')}
                                maxLength={255}
                                required
                                aria-invalid={errors.name ? true : undefined}
                                className="h-[var(--control-h-lg)]"
                            />
                        </Field>
                        <Field id="code" label={t('providers.code')} required error={errors.code} hint={t('providers.codeHint')}>
                            <Input
                                id="code"
                                value={data.code}
                                onChange={(event) => setData('code', event.target.value)}
                                placeholder={t('providers.codePlaceholder')}
                                maxLength={50}
                                required
                                aria-invalid={errors.code ? true : undefined}
                                className="h-[var(--control-h-lg)] font-mono"
                            />
                        </Field>
                    </div>
                </Section>

                <Section title={t('providers.sectionService')}>
                    <fieldset>
                        <legend className="mb-2 text-[13px] font-semibold text-[color:var(--app-foreground)]">
                            {t('providers.serviceType')} <RequiredMark />
                        </legend>
                        <div className="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                            {serviceTypes.map((type) => {
                                const selected = data.service_type_id === type.id;
                                const name = localName(type);
                                return (
                                    <label
                                        key={type.id}
                                        className={cx(
                                            'flex cursor-pointer items-center gap-2.5 rounded-[var(--radius-card)] border p-3 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[color:var(--color-primary)]',
                                            selected
                                                ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary-50)] ring-1 ring-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-950)]'
                                                : 'border-[color:var(--app-border)] hover:border-[color:var(--app-border-strong)]',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="service_type_id"
                                            value={type.id}
                                            checked={selected}
                                            onChange={() => setData('service_type_id', type.id)}
                                            className="sr-only"
                                        />
                                        <span aria-hidden="true" className={cx('inline-flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-md text-xs font-bold', typeTint(type.code))}>
                                            {initials(name)}
                                        </span>
                                        <span className="text-[13px] font-semibold leading-snug text-[color:var(--app-foreground)]">{name}</span>
                                    </label>
                                );
                            })}
                        </div>
                        {serviceTypes.length === 0 && <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('providers.noServiceTypes')}</p>}
                        <FieldError>{errors.service_type_id}</FieldError>
                    </fieldset>

                    <Field id="organization_id" label={t('providers.organization')} optional error={errors.organization_id} hint={t('providers.organizationHint')}>
                        <Select
                            id="organization_id"
                            value={data.organization_id}
                            onChange={(event) => setData('organization_id', event.target.value)}
                            className="h-[var(--control-h-lg)]"
                        >
                            <option value="">{t('providers.allOrganizations')}</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>{localName(org)}</option>
                            ))}
                        </Select>
                    </Field>
                </Section>

                <Section title={t('providers.status')}>
                    <fieldset>
                        <legend className="sr-only">{t('providers.status')}</legend>
                        <div className="grid gap-2.5 sm:grid-cols-3">
                            {PROVIDER_STATUSES.map((status) => {
                                const selected = data.status === status;
                                return (
                                    <label
                                        key={status}
                                        className={cx(
                                            'flex cursor-pointer gap-2.5 rounded-[var(--radius-card)] border px-3.5 py-3 transition-colors',
                                            selected
                                                ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary-50)] ring-1 ring-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-950)]'
                                                : 'border-[color:var(--app-border)] hover:border-[color:var(--app-border-strong)]',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="status"
                                            value={status}
                                            checked={selected}
                                            onChange={() => setData('status', status)}
                                            className="mt-0.5 h-4 w-4 border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]"
                                        />
                                        <span>
                                            <span className="flex items-center gap-1.5 text-sm font-semibold text-[color:var(--app-foreground)]">
                                                <StatusDot status={status} />
                                                {t(providerStatusKey(status))}
                                            </span>
                                            <span className="block text-xs text-[color:var(--app-muted-foreground)]">{t(STATUS_HINTS[status])}</span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                        <FieldError>{errors.status}</FieldError>
                    </fieldset>
                </Section>

                <div className="flex items-start justify-between gap-6 border-b border-[color:var(--app-border)] px-5 py-5 sm:px-6">
                    <div>
                        <p id="demo-label" className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('providers.demoTitle')}</p>
                        <p id="demo-hint" className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{t('providers.demoHint')}</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={data.is_demo}
                        aria-labelledby="demo-label"
                        aria-describedby="demo-hint"
                        onClick={() => setData('is_demo', !data.is_demo)}
                        className={cx(
                            'relative h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2',
                            data.is_demo ? 'bg-[color:var(--color-primary)]' : 'bg-[color:var(--app-border-strong)]',
                        )}
                    >
                        <span className={cx('absolute top-[3px] h-[18px] w-[18px] rounded-full bg-white shadow transition-[left]', data.is_demo ? 'left-[23px]' : 'left-[3px]')} />
                    </button>
                </div>

                <div className="flex justify-end gap-2 rounded-b-[var(--radius-panel)] bg-[color:var(--app-surface-muted)] px-5 py-4 sm:px-6">
                    <Link href={cancelHref} className={buttonClassName({ variant: 'ghost', size: 'lg' })}>{t('common.cancel')}</Link>
                    <Button type="submit" variant="primary" size="lg" loading={processing}>{submitLabel}</Button>
                </div>
            </form>

            <aside className="space-y-3 lg:sticky lg:top-20 lg:col-span-4">
                <h2 className="text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">{t('providers.previewInList')}</h2>
                <div aria-hidden="true" className="flex items-center gap-3 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                    <ProviderAvatar name={data.name || t('providers.previewName')} typeCode={selectedType?.code} />
                    <span className="min-w-0 flex-1">
                        <span className="flex items-center gap-1.5">
                            <span className="truncate text-sm font-semibold text-[color:var(--app-foreground)]">{data.name || t('providers.previewName')}</span>
                            {data.is_demo && <DemoTag />}
                        </span>
                        <span className="block truncate text-xs text-[color:var(--app-muted-foreground)]">
                            <span className="font-mono">{data.code || 'CODE'}</span> · {localName(selectedType, t('providers.unknownService'))}
                        </span>
                    </span>
                    <ProviderStatusBadge status={data.status} />
                </div>
                <p className="text-xs leading-relaxed text-[color:var(--app-muted-foreground)]">{t('providers.statusNote')}</p>
            </aside>
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }): JSX.Element {
    return (
        <section className="space-y-4 border-b border-[color:var(--app-border)] px-5 py-6 sm:px-6">
            <h2 className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{title}</h2>
            {children}
        </section>
    );
}

function RequiredMark(): JSX.Element {
    const { t } = useLocale();
    return (
        <>
            <span aria-hidden="true" className="text-red-600">*</span>
            <span className="sr-only">({t('providers.required')})</span>
        </>
    );
}

function Field({ id, label, required = false, optional = false, hint, error, children }: {
    id: string;
    label: string;
    required?: boolean;
    optional?: boolean;
    hint?: string;
    error?: string;
    children: ReactNode;
}): JSX.Element {
    const { t } = useLocale();
    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-[13px] font-semibold text-[color:var(--app-foreground)]">
                {label}{' '}
                {required && <RequiredMark />}
                {optional && <span className="font-normal text-[color:var(--app-muted-foreground)]">({t('providers.optionalShort')})</span>}
            </label>
            {children}
            {hint && !error && <p className="text-xs text-[color:var(--app-muted-foreground)]">{hint}</p>}
            <FieldError>{error}</FieldError>
        </div>
    );
}
