import { Link } from '@inertiajs/react';
import { buttonClassName } from '@euisis/ui';
import { useSituationLabels } from './useSituationLabels';
import type { SrCan, SrQuery } from '@/types/situationRoom';

/** Wall mode, reports and the action register — each shown only with its grant. */
export default function HeaderActions({ can, query }: { can: SrCan; query: SrQuery }) {
    const { sr } = useSituationLabels();

    return (
        <div className="flex flex-wrap gap-2">
            {can.actions_view && <Link href={route('situation-room.actions.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{sr('ui.actions')}</Link>}
            {can.reports && <Link href={route('situation-room.reports', query)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{sr('ui.reports')}</Link>}
            {can.wall && <Link href={route('situation-room.wall', query)} className={buttonClassName({ variant: 'primary', size: 'sm' })}>{sr('ui.wall_mode')}</Link>}
        </div>
    );
}
