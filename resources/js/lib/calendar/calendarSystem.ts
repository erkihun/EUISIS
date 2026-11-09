/**
 * Provides helpers for determining which calendar system to use.
 *
 * The locale is client-side (localStorage via LocaleContext) so we derive
 * the calendar system from it directly — never from the Inertia page props,
 * which are computed server-side and may lag behind a language switch.
 */

import { usePage } from '@inertiajs/react';
import { useLocaleContext } from '@/contexts/LocaleContext';
import type { CalendarSystem, CalendarMode, PageProps } from '@/types';

export type { CalendarSystem, CalendarMode };

/** Returns the active calendar system based on the current client-side locale. */
export function useCalendarSystem(): CalendarSystem {
    const { locale } = useLocaleContext();
    const page = usePage<PageProps>();
    const mode = page.props.calendar?.mode ?? 'locale_based';
    return calendarSystemForLocale(locale, mode);
}

/** Returns the calendar mode setting from Inertia shared props. */
export function useCalendarMode(): CalendarMode {
    const page = usePage<PageProps>();
    return page.props.calendar?.mode ?? 'locale_based';
}

/**
 * Determines the calendar system based on locale and the app's calendar mode.
 * This mirrors the backend `CalendarService::calendarSystemForLocale()` logic.
 */
export function calendarSystemForLocale(
    locale: string,
    mode: CalendarMode = 'locale_based',
): CalendarSystem {
    if (mode === 'gregorian_only') return 'gregorian';
    if (mode === 'ethiopian_only') return 'ethiopian';
    return locale === 'am' ? 'ethiopian' : 'gregorian';
}

/** True when the active calendar is Ethiopian. */
export function isEthiopianCalendar(system: CalendarSystem): boolean {
    return system === 'ethiopian';
}
