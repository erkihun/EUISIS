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
    /** Clears a filter panel. */
    reset: string;
    results: string;
    search: string;
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
    reset: 'Reset',
    results: 'results',
    search: 'Search',
};

const UiContext = createContext<UiMessages>(fallbackMessages);

export function UiProvider({ messages, children }: { messages: Partial<UiMessages>; children: ReactNode }) {
    return <UiContext.Provider value={{ ...fallbackMessages, ...messages }}>{children}</UiContext.Provider>;
}

export function useUiMessages(): UiMessages {
    return useContext(UiContext);
}
