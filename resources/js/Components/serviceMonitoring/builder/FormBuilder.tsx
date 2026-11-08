import { Alert, Button, Card, FieldError, FormField, Input, Select, Textarea, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { JSX, ReactNode } from 'react';
import { newKey } from './helpers';
import type { ColumnType, FormBlock, FormColumn, FormSchema, FormSection, RowMode, TableBlock } from './types';

export interface BuilderLimits { sections: number; blocks: number; columns: number; fixed_rows: number; dynamic_rows: number }

type Errors = Record<string, string | undefined>;

const COLUMN_TYPES: ColumnType[] = ['text', 'textarea', 'number', 'check', 'select', 'sum'];
const ROW_MODES: RowMode[] = ['dynamic', 'fixed', 'single'];

/** Move an item one place up (-1) or down (+1); out-of-range moves are ignored. */
function move<T>(items: T[], index: number, delta: -1 | 1): T[] {
    const target = index + delta;
    if (target < 0 || target >= items.length) return items;
    const next = [...items];
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}

function replace<T>(items: T[], index: number, item: T): T[] {
    return items.map((existing, i) => (i === index ? item : existing));
}

function blankBlock(type: FormBlock['type']): FormBlock {
    if (type === 'note') return { type, key: newKey(), text: '' };
    if (type === 'text') return { type, key: newKey(), label: '', required: false };
    return {
        type: 'table', key: newKey(), title: '', description: '', row_mode: 'dynamic', max_rows: 200, show_totals: true,
        column_groups: [], rows: [], columns: [{ key: newKey(), label: '', type: 'text' }],
    };
}

/**
 * The monitoring form builder. Administrators shape sections, tables, notes
 * and text answers; keys are generated, never typed. Server validation
 * (FormSchema) is authoritative; its errors arrive keyed by schema path.
 */
export default function FormBuilder({ schema, onChange, errors: serverErrors, limits, errorPrefix = 'schema' }: {
    schema: FormSchema;
    onChange: (schema: FormSchema) => void;
    errors: Errors;
    limits: BuilderLimits;
    /** Where the server reports this schema's problems (`content.custom` for a form template). */
    errorPrefix?: string;
}): JSX.Element {
    const { t } = useLocale();
    const errors: Errors = Object.fromEntries(Object.entries(serverErrors)
        .filter(([key]) => key.startsWith(`${errorPrefix}.`))
        .map(([key, message]) => [`schema.${key.slice(errorPrefix.length + 1)}`, message]));
    const hasErrors = Object.keys(errors).length > 0;
    const setSections = (sections: FormSection[]) => onChange({ ...schema, sections });

    return (
        <div className="space-y-4">
            {hasErrors && <Alert tone="danger">{t('serviceMonitoring.templates.builderErrors')}</Alert>}
            {errors['schema.sections'] && <FieldError>{errors['schema.sections']}</FieldError>}
            {schema.sections.length === 0 && (
                <div className="border border-dashed border-(--app-border-strong) p-5 text-center">
                    <p className="text-sm font-medium text-(--app-foreground)">{t('serviceMonitoring.templates.builder.noSections')}</p>
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('serviceMonitoring.templates.builder.noSectionsHelp')}</p>
                </div>
            )}

            {schema.sections.map((section, s) => (
                <Card key={section.key} className="p-4">
                    <ItemToolbar
                        title={`${t('serviceMonitoring.templates.builder.section')} ${s + 1}`}
                        onUp={() => setSections(move(schema.sections, s, -1))}
                        onDown={() => setSections(move(schema.sections, s, 1))}
                        onRemove={() => setSections(schema.sections.filter((_, i) => i !== s))}
                    />
                    <div className="mt-4">
                        <FormField label={t('serviceMonitoring.templates.builder.sectionTitle')} required error={errors[`schema.sections.${s}.title`]}>
                            {({ id }) => <Input id={id} className="w-full" maxLength={500} value={section.title} onChange={(e) => setSections(replace(schema.sections, s, { ...section, title: e.target.value }))} />}
                        </FormField>
                    </div>

                    {errors[`schema.sections.${s}.blocks`] && <FieldError>{errors[`schema.sections.${s}.blocks`]}</FieldError>}
                    <div className="mt-4 space-y-3">
                        {section.blocks.map((block, b) => {
                            const path = `schema.sections.${s}.blocks.${b}`;
                            const setBlocks = (blocks: FormBlock[]) => setSections(replace(schema.sections, s, { ...section, blocks }));
                            const setBlock = (next: FormBlock) => setBlocks(replace(section.blocks, b, next));

                            if (block.type === 'text') {
                                return (
                                    <div key={block.key} className="border-s-4 border-(--color-primary) bg-(--app-surface-muted) p-3">
                                        <ItemToolbar
                                            title={`${t('serviceMonitoring.templates.builder.question')} ${b + 1}`}
                                            onUp={() => setBlocks(move(section.blocks, b, -1))}
                                            onDown={() => setBlocks(move(section.blocks, b, 1))}
                                            onRemove={() => setBlocks(section.blocks.filter((_, i) => i !== b))}
                                        />
                                        <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                            <FormField label={t('serviceMonitoring.templates.builder.questionLabel')} required error={errors[`${path}.label`]}>
                                                {({ id }) => <Input id={id} className="w-full" maxLength={500} value={block.label} onChange={(e) => setBlock({ ...block, label: e.target.value })} />}
                                            </FormField>
                                            <Checkbox label={t('serviceMonitoring.templates.builder.required')} checked={Boolean(block.required)} onChange={(required) => setBlock({ ...block, required })} />
                                        </div>
                                    </div>
                                );
                            }

                            if (block.type === 'note') {
                                return (
                                    <details key={block.key} className="border border-(--app-border) p-3">
                                        <summary className="cursor-pointer text-sm font-medium">{t('serviceMonitoring.templates.builder.legacyNote')}</summary>
                                        <div className="mt-3 space-y-3">
                                            <ItemToolbar title={t('serviceMonitoring.templates.builder.blockTypes.note')} onUp={() => setBlocks(move(section.blocks, b, -1))} onDown={() => setBlocks(move(section.blocks, b, 1))} onRemove={() => setBlocks(section.blocks.filter((_, i) => i !== b))} />
                                            <FormField label={t('serviceMonitoring.templates.builder.noteText')} required error={errors[`${path}.text`]}>
                                                {({ id }) => <Textarea id={id} className="w-full" maxLength={5000} value={block.text} onChange={(e) => setBlock({ ...block, text: e.target.value })} />}
                                            </FormField>
                                        </div>
                                    </details>
                                );
                            }

                            return (
                                <details key={block.key} className="border border-(--app-border) p-3" open>
                                    <summary className="cursor-pointer text-sm font-semibold">{block.title || t('serviceMonitoring.templates.builder.blockTypes.table')}</summary>
                                    <div className="mt-3">
                                        <ItemToolbar title={t('serviceMonitoring.templates.builder.blockTypes.table')} onUp={() => setBlocks(move(section.blocks, b, -1))} onDown={() => setBlocks(move(section.blocks, b, 1))} onRemove={() => setBlocks(section.blocks.filter((_, i) => i !== b))} />
                                        <div className="mt-3"><TableEditor block={block} path={path} errors={errors} limits={limits} onChange={setBlock} /></div>
                                    </div>
                                </details>
                            );
                        })}
                    </div>

                    {section.blocks.length < limits.blocks && (
                        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-(--app-border) pt-3">
                            <Button type="button" variant="secondary" size="sm" onClick={() => setSections(replace(schema.sections, s, { ...section, blocks: [...section.blocks, blankBlock('text')] }))}>
                                {t('serviceMonitoring.templates.actions.addText')}
                            </Button>
                            <details className="relative">
                                <summary className="cursor-pointer text-sm font-medium text-(--app-muted-foreground) underline underline-offset-4">{t('serviceMonitoring.templates.builder.advancedData')}</summary>
                                <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => setSections(replace(schema.sections, s, { ...section, blocks: [...section.blocks, blankBlock('table')] }))}>
                                    {t('serviceMonitoring.templates.actions.addTable')}
                                </Button>
                            </details>
                        </div>
                    )}
                </Card>
            ))}

            {schema.sections.length < limits.sections && (
                <Button type="button" variant="outline" onClick={() => setSections([...schema.sections, { key: newKey(), title: '', blocks: [] }])}>
                    {t('serviceMonitoring.templates.actions.addSection')}
                </Button>
            )}
        </div>
    );
}

function TableEditor({ block, path, errors, limits, onChange }: {
    block: TableBlock;
    path: string;
    errors: Errors;
    limits: BuilderLimits;
    onChange: (block: TableBlock) => void;
}): JSX.Element {
    const { t } = useLocale();
    const groups = block.column_groups ?? [];
    const rows = block.rows ?? [];
    const numberColumns = block.columns.filter((column) => column.type === 'number');

    const setColumn = (index: number, column: FormColumn) => onChange({ ...block, columns: replace(block.columns, index, column) });

    return (
        <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-2">
                <FormField label={t('serviceMonitoring.templates.builder.tableTitle')} required error={errors[`${path}.title`]}>
                    {({ id }) => <Input id={id} className="w-full" maxLength={500} value={block.title} onChange={(e) => onChange({ ...block, title: e.target.value })} />}
                </FormField>
                <FormField label={t('serviceMonitoring.templates.builder.rowMode')} error={errors[`${path}.row_mode`]}>
                    {({ id }) => (
                        <Select id={id} className="w-full" value={block.row_mode} onChange={(e) => onChange({ ...block, row_mode: e.target.value as RowMode })}>
                            {ROW_MODES.map((mode) => <option key={mode} value={mode}>{t(`serviceMonitoring.templates.builder.rowModes.${mode}`)}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField label={t('serviceMonitoring.templates.builder.tableDescription')} className="md:col-span-2">
                    {({ id }) => <Input id={id} className="w-full" maxLength={1000} value={block.description ?? ''} onChange={(e) => onChange({ ...block, description: e.target.value })} />}
                </FormField>
                {block.row_mode === 'dynamic' && (
                    <FormField label={t('serviceMonitoring.templates.builder.maxRows')} error={errors[`${path}.max_rows`]}>
                        {({ id }) => <Input id={id} type="number" min={1} max={limits.dynamic_rows} className="w-full" value={block.max_rows ?? ''}
                            onChange={(e) => onChange({ ...block, max_rows: e.target.value === '' ? undefined : Math.trunc(Number(e.target.value)) })} />}
                    </FormField>
                )}
                <div className="flex items-end">
                    <Checkbox label={t('serviceMonitoring.templates.builder.showTotals')} checked={block.show_totals} onChange={(show_totals) => onChange({ ...block, show_totals })} />
                </div>
            </div>

            {/* Column groups: one heading over several columns. */}
            <Subsection title={t('serviceMonitoring.templates.builder.groups')} help={t('serviceMonitoring.templates.builder.groupsHelp')}>
                {groups.map((group, g) => (
                    <div key={group.key} className="flex items-start gap-2">
                        <FormField label={t('serviceMonitoring.templates.builder.groupLabel')} className="flex-1" error={errors[`${path}.column_groups.${g}.label`]}>
                            {({ id }) => <Input id={id} className="w-full" maxLength={500} value={group.label} onChange={(e) => onChange({ ...block, column_groups: replace(groups, g, { ...group, label: e.target.value }) })} />}
                        </FormField>
                        <RemoveButton className="mt-6" onClick={() => onChange({
                            ...block,
                            column_groups: groups.filter((_, i) => i !== g),
                            columns: block.columns.map((column) => (column.group === group.key ? { ...column, group: null } : column)),
                        })} />
                    </div>
                ))}
                <Button type="button" variant="ghost" size="sm" onClick={() => onChange({ ...block, column_groups: [...groups, { key: newKey(), label: '' }] })}>
                    {t('serviceMonitoring.templates.actions.addGroup')}
                </Button>
            </Subsection>

            <Subsection title={t('serviceMonitoring.templates.builder.columns')}>
                {errors[`${path}.columns`] && <FieldError>{errors[`${path}.columns`]}</FieldError>}
                {block.columns.map((column, c) => {
                    const columnPath = `${path}.columns.${c}`;
                    return (
                        <div key={column.key} className="space-y-2 rounded-card border border-(--app-border) bg-(--app-surface) p-3">
                            <div className="grid gap-2 md:grid-cols-[2fr_1fr_1fr_auto] md:items-end">
                                <FormField label={t('serviceMonitoring.templates.builder.columnLabel')} required error={errors[`${columnPath}.label`]}>
                                    {({ id }) => <Input id={id} className="w-full" maxLength={500} value={column.label} onChange={(e) => setColumn(c, { ...column, label: e.target.value })} />}
                                </FormField>
                                <FormField label={t('serviceMonitoring.templates.builder.columnType')} error={errors[`${columnPath}.type`]}>
                                    {({ id }) => (
                                        <Select id={id} className="w-full" value={column.type} onChange={(e) => {
                                            const type = e.target.value as ColumnType;
                                            setColumn(c, { key: column.key, label: column.label, group: column.group, required: column.required, aggregation: column.aggregation, type,
                                                ...(type === 'select' ? { options: column.options ?? [] } : {}),
                                                ...(type === 'sum' ? { sum_of: [] } : {}) });
                                        }}>
                                            {COLUMN_TYPES.map((type) => <option key={type} value={type}>{t(`serviceMonitoring.templates.builder.columnTypes.${type}`)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField label={t('serviceMonitoring.templates.builder.group')} error={errors[`${columnPath}.group`]}>
                                    {({ id }) => (
                                        <Select id={id} className="w-full" value={column.group ?? ''} onChange={(e) => setColumn(c, { ...column, group: e.target.value || null })}>
                                            <option value="">{t('serviceMonitoring.templates.builder.noGroup')}</option>
                                            {groups.map((group) => <option key={group.key} value={group.key}>{group.label || '…'}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <MoveButtons
                                    onUp={() => onChange({ ...block, columns: move(block.columns, c, -1) })}
                                    onDown={() => onChange({ ...block, columns: move(block.columns, c, 1) })}
                                    onRemove={block.columns.length > 1 ? () => onChange({
                                        ...block,
                                        columns: block.columns.filter((_, i) => i !== c)
                                            .map((other) => (other.type === 'sum' ? { ...other, sum_of: (other.sum_of ?? []).filter((key) => key !== column.key) } : other)),
                                    }) : undefined}
                                />
                            </div>
                            {column.type !== 'sum' && column.type !== 'check' && (
                                <Checkbox label={t('serviceMonitoring.templates.builder.required')} checked={Boolean(column.required)} onChange={(required) => setColumn(c, { ...column, required })} />
                            )}
                            {(column.type === 'number' || column.type === 'sum') && (
                                <FormField label={t('serviceMonitoring.templates.builder.aggregation')} description={t('serviceMonitoring.templates.builder.aggregationHelp')} error={errors[`${columnPath}.aggregation`]}>
                                    {({ id }) => (
                                        <Select id={id} className="w-full md:w-72" value={column.aggregation ?? 'flow'} onChange={(e) => setColumn(c, { ...column, aggregation: e.target.value as 'flow' | 'stock' })}>
                                            <option value="flow">{t('serviceMonitoring.templates.builder.aggregations.flow')}</option>
                                            <option value="stock">{t('serviceMonitoring.templates.builder.aggregations.stock')}</option>
                                        </Select>
                                    )}
                                </FormField>
                            )}
                            {column.type === 'select' && (
                                <FormField label={t('serviceMonitoring.templates.builder.options')} error={errors[`${columnPath}.options`]}>
                                    {({ id }) => <Textarea id={id} className="w-full" value={(column.options ?? []).join('\n')}
                                        onChange={(e) => setColumn(c, { ...column, options: e.target.value.split('\n').map((line) => line.trim()).filter(Boolean) })} />}
                                </FormField>
                            )}
                            {column.type === 'sum' && (
                                <fieldset className="space-y-1">
                                    <legend className="text-xs font-medium text-(--app-muted-foreground)">{t('serviceMonitoring.templates.builder.sumOf')} — {t('serviceMonitoring.templates.builder.sumOfHelp')}</legend>
                                    {numberColumns.length === 0 && <p className="text-xs text-(--app-muted-foreground)">{t('serviceMonitoring.templates.builder.noNumberColumns')}</p>}
                                    <div className="flex flex-wrap gap-x-4 gap-y-1">
                                        {numberColumns.map((source) => (
                                            <Checkbox key={source.key} label={source.label || '…'} checked={(column.sum_of ?? []).includes(source.key)}
                                                onChange={(on) => setColumn(c, { ...column, sum_of: on ? [...(column.sum_of ?? []), source.key] : (column.sum_of ?? []).filter((key) => key !== source.key) })} />
                                        ))}
                                    </div>
                                    {errors[`${columnPath}.sum_of`] && <FieldError>{errors[`${columnPath}.sum_of`]}</FieldError>}
                                </fieldset>
                            )}
                        </div>
                    );
                })}
                {block.columns.length < limits.columns && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => onChange({ ...block, columns: [...block.columns, { key: newKey(), label: '', type: 'number' }] })}>
                        {t('serviceMonitoring.templates.actions.addColumn')}
                    </Button>
                )}
            </Subsection>

            {block.row_mode === 'fixed' && (
                <Subsection title={t('serviceMonitoring.templates.builder.rows')}>
                    {errors[`${path}.rows`] && <FieldError>{errors[`${path}.rows`]}</FieldError>}
                    {rows.map((row, r) => (
                        <div key={row.key} className="flex items-start gap-2">
                            <span className="mt-2 w-6 text-right text-xs text-(--app-muted-foreground)">{r + 1}</span>
                            <FormField label={t('serviceMonitoring.templates.builder.rowLabel')} className="flex-1" error={errors[`${path}.rows.${r}.label`]}>
                                {({ id }) => <Input id={id} className="w-full" maxLength={500} value={row.label} onChange={(e) => onChange({ ...block, rows: replace(rows, r, { ...row, label: e.target.value }) })} />}
                            </FormField>
                            <MoveButtons className="mt-6"
                                onUp={() => onChange({ ...block, rows: move(rows, r, -1) })}
                                onDown={() => onChange({ ...block, rows: move(rows, r, 1) })}
                                onRemove={() => onChange({ ...block, rows: rows.filter((_, i) => i !== r) })} />
                        </div>
                    ))}
                    {rows.length < limits.fixed_rows && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => onChange({ ...block, rows: [...rows, { key: newKey(), label: '' }] })}>
                            {t('serviceMonitoring.templates.actions.addRow')}
                        </Button>
                    )}
                </Subsection>
            )}
        </div>
    );
}

function Subsection({ title, help, children }: { title: string; help?: string; children: ReactNode }): JSX.Element {
    return (
        <div className="space-y-2">
            <div>
                <p className="text-xs font-semibold text-(--app-muted-foreground)">{title}</p>
                {help && <p className="text-xs text-(--app-muted-foreground)">{help}</p>}
            </div>
            {children}
        </div>
    );
}

function ItemToolbar({ title, onUp, onDown, onRemove }: { title: string; onUp: () => void; onDown: () => void; onRemove: () => void }): JSX.Element {
    return (
        <div className="flex items-center justify-between gap-2">
            <p className="text-sm font-semibold text-(--app-foreground)">{title}</p>
            <MoveButtons onUp={onUp} onDown={onDown} onRemove={onRemove} />
        </div>
    );
}

function MoveButtons({ onUp, onDown, onRemove, className }: { onUp: () => void; onDown: () => void; onRemove?: () => void; className?: string }): JSX.Element {
    const { t } = useLocale();
    return (
        <div className={cx('flex items-center gap-1', className)}>
            <Button type="button" variant="ghost" size="xs" aria-label={t('serviceMonitoring.templates.actions.moveUp')} title={t('serviceMonitoring.templates.actions.moveUp')} onClick={onUp}>↑</Button>
            <Button type="button" variant="ghost" size="xs" aria-label={t('serviceMonitoring.templates.actions.moveDown')} title={t('serviceMonitoring.templates.actions.moveDown')} onClick={onDown}>↓</Button>
            {onRemove && <RemoveButton onClick={onRemove} />}
        </div>
    );
}

function RemoveButton({ onClick, className }: { onClick: () => void; className?: string }): JSX.Element {
    const { t } = useLocale();
    return (
        <Button type="button" variant="ghost" size="xs" className={className} aria-label={t('serviceMonitoring.templates.actions.remove')} title={t('serviceMonitoring.templates.actions.remove')} onClick={onClick}>×</Button>
    );
}

function Checkbox({ label, checked, onChange }: { label: string; checked: boolean; onChange: (checked: boolean) => void }): JSX.Element {
    return (
        <label className="inline-flex items-center gap-2 text-sm text-(--app-foreground)">
            <input type="checkbox" className="h-4 w-4 accent-(--color-primary)" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            {label}
        </label>
    );
}
