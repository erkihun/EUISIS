import { createContext, useContext, type ReactNode } from 'react';

export interface UiMessages {
    actions: string;
    cancel: string;
    clear: string;
    confirm: string;
    loading: string;
    noResults: string;
    next: string;
    previous: string;
    remove: string;
    replace: string;
    results: string;
    search: string;
}

const fallbackMessages: UiMessages = {
    actions: 'Actions',
    cancel: 'Cancel',
    clear: 'Clear',
    confirm: 'Confirm',
    loading: 'Loading',
    noResults: 'No results found',
    next: 'Next',
    previous: 'Previous',
    remove: 'Remove',
    replace: 'Replace',
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
