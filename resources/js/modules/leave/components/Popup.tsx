import { X } from '@/Components/Icons';
import Modal from '@/Components/Modal';
import { cx } from '@/Components/ui';
import { mutedCls, sectionTitleCls, useLeave } from '@/modules/leave/lib/helpers';
import { router } from '@inertiajs/react';
import { useState, type JSX, type ReactNode } from 'react';

/**
 * The one popup of the leave module: a wide card with a title bar that says
 * what is being done and for whom, a close button, and a body that scrolls
 * inside the card. Every list page opens its records and forms in it.
 */
export function LeavePopup({ show, title, subtitle, onClose, children }: {
    show: boolean;
    title: string;
    subtitle?: string | null;
    onClose: () => void;
    children: ReactNode;
}): JSX.Element {
    const { lt } = useLeave();

    return (
        <Modal show={show} onClose={onClose} maxWidth="6xl">
            <div className="flex max-h-[85vh] flex-col">
                <div className="flex items-start justify-between gap-3 border-b border-(--app-border) px-5 py-4">
                    <div className="min-w-0">
                        <h2 className={sectionTitleCls}>{title}</h2>
                        {subtitle && <p className={cx(mutedCls, 'truncate text-xs')}>{subtitle}</p>}
                    </div>
                    <button type="button" onClick={onClose} aria-label={lt('common.close')} className="rounded-control p-1 text-(--app-muted-foreground) hover:bg-primary/10 hover:text-(--app-foreground)">
                        <X className="h-5 w-5" aria-hidden />
                    </button>
                </div>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">{children}</div>
            </div>
        </Modal>
    );
}

/** Shown in a popup while the record it is about is on its way from the server. */
export function PopupLoading(): JSX.Element {
    const { lt } = useLeave();

    return <p className={mutedCls} role="status">{lt('common.loading')}</p>;
}

/**
 * A popup about one record of the page. The record is named in the query
 * string (`?request=<id>`, `?selected=<id>`): opening asks the server for
 * that record's part of the page only; a save that comes back to the page
 * brings it fresh; closing drops it and refreshes the list underneath.
 *
 * @param param    the query parameter that names the record
 * @param prop     the page prop that carries the record
 * @param refresh  the list props to reload when the popup closes
 * @param initial  the record the page arrived with (a link that opens the popup at once)
 */
export function usePopupRecord(param: string, prop: string, refresh: string[], initial: string | null = null) {
    const [openId, setOpenId] = useState<string | null>(initial);
    const open = (id: string) => {
        setOpenId(id);
        router.reload({ data: { [param]: id }, only: [prop, 'errors'] });
    };
    const close = () => {
        setOpenId(null);
        router.reload({ data: { [param]: '' }, only: [prop, 'errors', ...refresh] });
    };

    return { openId, open, close };
}
