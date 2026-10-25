import { Fragment, useEffect, useRef, useState } from 'react';
import { Command } from 'cmdk';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { SearchIcon as Search } from './NavIcons';
import { useAuth } from '../auth';
import { useLocale } from '../i18n';
import { HOME, visibleSections } from '../navigation';
import { navigate } from '../router';

/** EUISIS Components/ui/AppCommandPalette.tsx over the Core navigation: Ctrl K, type, Enter. */
export default function AppCommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { t } = useLocale();
    const { user } = useAuth();
    const [search, setSearch] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const groups = [
        { labelKey: 'core.workspaceName', items: [HOME] },
        ...visibleSections(Boolean(user?.can_change_master_data)).flatMap((section) => section.groups),
    ];

    useEffect(() => {
        if (open) setSearch('');
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const id = setTimeout(() => inputRef.current?.focus(), 50);
        return () => clearTimeout(id);
    }, [open]);

    function go(path: string) {
        navigate(path);
        onClose();
    }

    return (
        <Transition show={open} as={Fragment}>
            <Dialog as="div" className="fixed inset-0 z-50" onClose={onClose} aria-label={t('nav.commandMenu')}>
                <TransitionChild as={Fragment} enter="ease-out duration-150" enterFrom="opacity-0" enterTo="opacity-100" leave="ease-in duration-100" leaveFrom="opacity-100" leaveTo="opacity-0">
                    <div className="absolute inset-0 bg-black/50 backdrop-blur-xs" aria-hidden="true" />
                </TransitionChild>

                <TransitionChild as={Fragment} enter="ease-out duration-150" enterFrom="opacity-0 scale-95 translate-y-2" enterTo="opacity-100 scale-100 translate-y-0" leave="ease-in duration-100" leaveFrom="opacity-100 scale-100 translate-y-0" leaveTo="opacity-0 scale-95 translate-y-2">
                    <DialogPanel className="relative mx-auto mt-20 w-full max-w-lg overflow-hidden rounded-panel bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
                        <Command className="flex flex-col" shouldFilter onKeyDown={(e) => { if (e.key === 'Escape') onClose(); }}>
                            <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-slate-800">
                                <Search className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                <Command.Input
                                    ref={inputRef}
                                    value={search}
                                    onValueChange={setSearch}
                                    placeholder={t('nav.typeCommandOrSearch')}
                                    className="flex-1 bg-transparent text-sm text-gray-900 placeholder:text-gray-400 focus:outline-hidden dark:text-slate-100 dark:placeholder:text-slate-500"
                                />
                                <kbd className="hidden rounded-sm border border-gray-200 px-1.5 py-0.5 text-[10px] font-medium text-gray-400 sm:block dark:border-slate-700 dark:text-slate-500">ESC</kbd>
                            </div>

                            <Command.List className="max-h-80 overflow-y-auto py-2">
                                <Command.Empty className="px-4 py-8 text-center text-sm text-gray-500 dark:text-slate-400">{t('nav.noResults')}</Command.Empty>
                                {groups.map((group) => (
                                    <Command.Group key={group.labelKey} heading={t(group.labelKey)} className="mb-1">
                                        <div className="mb-1 px-3 pt-2 text-[10px] font-semibold text-gray-400 dark:text-slate-500">{t(group.labelKey)}</div>
                                        {group.items.map((item) => {
                                            const Icon = item.icon;
                                            return (
                                                <Command.Item
                                                    key={item.path}
                                                    value={`${t(item.labelKey)} ${t(group.labelKey)}`}
                                                    onSelect={() => go(item.path)}
                                                    className="flex cursor-pointer items-center gap-3 px-4 py-2 text-sm text-gray-700 data-[selected=true]:bg-blue-50 data-[selected=true]:text-blue-700 dark:text-slate-300 dark:data-[selected=true]:bg-blue-900/30 dark:data-[selected=true]:text-blue-300"
                                                >
                                                    <Icon className="h-4 w-4 shrink-0 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                                                    {t(item.labelKey)}
                                                </Command.Item>
                                            );
                                        })}
                                    </Command.Group>
                                ))}
                            </Command.List>

                            <div className="flex items-center justify-end gap-3 border-t border-gray-100 px-4 py-2 dark:border-slate-800">
                                <span className="text-[11px] text-gray-400 dark:text-slate-500">
                                    <kbd className="rounded-sm border border-gray-200 px-1 dark:border-slate-700">↑</kbd>
                                    <kbd className="ml-0.5 rounded-sm border border-gray-200 px-1 dark:border-slate-700">↓</kbd>
                                </span>
                                <span className="text-[11px] text-gray-400 dark:text-slate-500">
                                    <kbd className="rounded-sm border border-gray-200 px-1 dark:border-slate-700">↵</kbd>
                                </span>
                            </div>
                        </Command>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}
