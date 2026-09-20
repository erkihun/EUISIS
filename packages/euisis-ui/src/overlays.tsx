import { Dialog, DialogPanel, DialogTitle, Tab, TabGroup, TabList, TabPanel, TabPanels, Transition, TransitionChild } from '@headlessui/react';
import { Fragment, type ReactNode } from 'react';
import { useUiMessages } from './context';
import { Button, cx } from './primitives';

export function AppDialog({ open, onClose, title, description, children, footer, maxWidth = 'md' }: {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    children?: ReactNode;
    footer?: ReactNode;
    maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
}) {
    const widths = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl' };
    return <Transition show={open} as={Fragment}>
        <Dialog onClose={onClose} className="relative z-50">
            <TransitionChild enter="ease-out duration-200" enterFrom="opacity-0" enterTo="opacity-100" leave="ease-in duration-150" leaveFrom="opacity-100" leaveTo="opacity-0"><div className="fixed inset-0 bg-black/50" aria-hidden="true" /></TransitionChild>
            <div className="fixed inset-0 overflow-y-auto p-4"><div className="flex min-h-full items-center justify-center"><TransitionChild enter="ease-out duration-200" enterFrom="opacity-0 scale-95" enterTo="opacity-100 scale-100" leave="ease-in duration-150" leaveFrom="opacity-100 scale-100" leaveTo="opacity-0 scale-95"><DialogPanel className={cx('w-full rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] shadow-xl', widths[maxWidth])}><div className="p-5"><DialogTitle className="text-base font-semibold">{title}</DialogTitle>{description && <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{description}</p>}{children && <div className="mt-4">{children}</div>}</div>{footer && <div className="flex flex-wrap justify-end gap-2 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-5 py-4">{footer}</div>}</DialogPanel></TransitionChild></div></div>
        </Dialog>
    </Transition>;
}

export function ConfirmDialog({ open, onClose, onConfirm, title, description, confirmLabel, cancelLabel, destructive = false, loading = false }: {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: ReactNode;
    description?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
    loading?: boolean;
}) {
    const messages = useUiMessages();
    return <AppDialog open={open} onClose={() => { if (!loading) onClose(); }} title={title} description={description} footer={<><Button variant="outline" onClick={onClose} disabled={loading}>{cancelLabel ?? messages.cancel}</Button><Button variant={destructive ? 'destructive' : 'default'} onClick={onConfirm} loading={loading}>{confirmLabel ?? messages.confirm}</Button></>}/>;
}

export interface TabItem { id: string; label: ReactNode; content: ReactNode; disabled?: boolean }
export function Tabs({ items, className }: { items: TabItem[]; className?: string }) {
    return <TabGroup className={className}><TabList className="flex gap-1 overflow-x-auto border-b border-[color:var(--app-border)]">{items.map((item) => <Tab key={item.id} disabled={item.disabled} className="whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm text-[color:var(--app-muted-foreground)] data-[selected]:border-[color:var(--color-primary)] data-[selected]:font-medium data-[selected]:text-[color:var(--color-primary)] disabled:opacity-50">{item.label}</Tab>)}</TabList><TabPanels>{items.map((item) => <TabPanel key={item.id} className="pt-4">{item.content}</TabPanel>)}</TabPanels></TabGroup>;
}
