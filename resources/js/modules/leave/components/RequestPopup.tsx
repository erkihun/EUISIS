import { ActionErrors } from '@/modules/leave/components/Common';
import { LeavePopup, PopupLoading } from '@/modules/leave/components/Popup';
import { RequestDetail } from '@/modules/leave/components/RequestDetail';
import { secondaryBtn, useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveDetail } from '@/modules/leave/types';
import { Link } from '@inertiajs/react';
import type { JSX } from 'react';

export interface OpenRequest {
    detail: LeaveDetail;
    balance: { available: number; pending: number; free: number } | null;
}

/** One leave request in the module's popup, opened from any list of requests. */
export function RequestPopup({ openId, record, staff, today, fullHref, onClose }: {
    /** The request the popup is open on; null when closed. */
    openId: string | null;
    /** The request as the server answered it; it may still be on its way, or be the one opened before. */
    record: OpenRequest | null;
    staff: boolean;
    today: string;
    fullHref: (id: string) => string;
    onClose: () => void;
}): JSX.Element {
    const { lt, name } = useLeave();
    const loaded = openId !== null && record?.detail.id === openId ? record : null;

    return (
        <LeavePopup show={openId !== null} onClose={onClose}
            title={loaded ? `${lt('show.title')} ${loaded.detail.reference_number}` : lt('show.title')}
            subtitle={loaded ? `${name(loaded.detail.employee)} · ${name(loaded.detail.leave_type)}` : null}>
            <ActionErrors />
            {!loaded ? <PopupLoading /> : (
                <>
                    <RequestDetail key={loaded.detail.id} leave={loaded.detail} staff={staff} balance={loaded.balance} today={today} />
                    <div className="flex flex-wrap justify-end gap-3">
                        <Link href={fullHref(loaded.detail.id)} className={secondaryBtn}>{lt('table.openPage')}</Link>
                        <button type="button" className={secondaryBtn} onClick={onClose}>{lt('common.close')}</button>
                    </div>
                </>
            )}
        </LeavePopup>
    );
}
