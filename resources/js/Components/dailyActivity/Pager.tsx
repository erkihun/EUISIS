import ListPagination from '@/Components/ui/ListPagination';
import type { PageLink, PaginationMetaData } from './types';

/** The shared pagination bar for a resource collection; keeps the current filters. */
export default function Pager({ meta }: { meta: PaginationMetaData; links?: PageLink[] }) {
    return <ListPagination meta={meta} bare className="pt-3" />;
}
