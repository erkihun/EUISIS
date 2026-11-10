import { createContext, useContext, type ReactNode } from 'react';

export interface UiMessages {
    actions: string;
    cancel: string;
    clear: string;
    confirm: string;
    /** Submit label of a filter panel. */
    filter: string;
    /** Accessible name of a filter panel. */
    filters: string;
    loading: string;
    noResults: string;
    next: string;
    /** Accessible name of a page button: "Page 3". */
    page: string;
    /** Accessible name of a pagination bar. */
    pagination: string;
    previous: string;
    /** Visible range of a paged list; :from, :to and :total are replaced. */
    range: string;
    remove: string;
    replace: string;
    /** Screen-reader word after a required field's label. */
    required: string;
    /** Clears a filter panel. */
    reset: string;
    results: string;
    search: string;
    /** TableTools: the columns menu. */
    columns: string;
    showAllColumns: string;
    /** TableTools: row density and its two values. */
    density: string;
    compact: string;
    comfortable: string;
    /** TableTools: keep the header row visible while the rows scroll. */
    stickyHeader: string;
    resetTable: string;
    /** TableTools: a sort that applies to the rows on this page only. */
    sortedOnPage: string;
    /** Header tooltip; :column is replaced. */
    sortBy: string;
    /** Accessible name of the table toolbar. */
    tableTools: string;
}

const fallbackMessages: UiMessages = {
    actions: 'Actions',
    cancel: 'Cancel',
    clear: 'Clear',
    confirm: 'Confirm',
    filter: 'Filter',
    filters: 'Filters',
    loading: 'Loading',
    noResults: 'No results found',
    next: 'Next',
    page: 'Page',
    pagination: 'Pagination',
    previous: 'Previous',
    range: ':from–:to of :total',
    remove: 'Remove',
    replace: 'Replace',
    required: 'required',
    reset: 'Reset',
    results: 'results',
    search: 'Search',
    columns: 'Columns',
    showAllColumns: 'Show all columns',
    density: 'Row density',
    compact: 'Compact',
    comfortable: 'Comfortable',
    stickyHeader: 'Keep header visible',
    resetTable: 'Reset table',
    sortedOnPage: 'Sorted on this page only',
    sortBy: 'Sort by :column',
    tableTools: 'Table tools',
};

const UiContext = createContext<UiMessages>(fallbackMessages);

/**
 * A label-to-icon rule: when a Button or an action-menu item is given only a
 * text label and that label (trimmed, case-folded, without a trailing
 * ellipsis) equals one of `labels`, `icon` is drawn before it. The app
 * supplies the rules from its translations, so every "Save", "Delete" or
 * "Export" gets the same glyph in both languages without each page adding one.
 */
export interface ActionIconRule {
    labels: string[];
    icon: ReactNode;
}

const ActionIconContext = createContext<ActionIconRule[]>([]);

function normalizeLabel(label: string): string {
    return label.replace(/(\.\.\.|…)\s*$/u, '').trim().toLocaleLowerCase();
}

export function UiProvider({ messages, actionIcons = [], children }: { messages: Partial<UiMessages>; actionIcons?: ActionIconRule[]; children: ReactNode }) {
    return (
        <UiContext.Provider value={{ ...fallbackMessages, ...messages }}>
            <ActionIconContext.Provider value={actionIcons}>{children}</ActionIconContext.Provider>
        </UiContext.Provider>
    );
}

export function useUiMessages(): UiMessages {
    return useContext(UiContext);
}

/** The icon the rules give a text label, or null when the label is not text or has no rule. */
export function useActionIcon(label: ReactNode): ReactNode | null {
    const rules = useContext(ActionIconContext);
    if (typeof label !== 'string' || rules.length === 0) return null;
    const key = normalizeLabel(label);
    if (key === '') return null;
    // Exact label first ("Save"), then a label that begins or ends with the verb as a whole word
    // ("Save employee", Amharic "ሠራተኛ ያስቀምጡ"), so entity-named actions get the same glyph.
    for (const rule of rules) {
        if (rule.labels.some((candidate) => normalizeLabel(candidate) === key)) return rule.icon;
    }
    for (const rule of rules) {
        if (rule.labels.some((candidate) => {
            const verb = normalizeLabel(candidate);
            return verb.length > 1 && (key.startsWith(verb + ' ') || key.endsWith(' ' + verb));
        })) return rule.icon;
    }
    return null;
}
