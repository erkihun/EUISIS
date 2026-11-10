import { useMemo } from 'react';
import type { ActionIconRule } from '@euisis/ui';

/*
 * The glyph every text action gets, by its label (both languages). The kit's
 * Button and ActionMenu read these rules through UiProvider, so "Save",
 * "Delete", "Export" and the rest carry the same icon on every page without
 * each page adding one. The paths are Lucide outlines kept here rather than
 * imported from @/Components/Icons, so the sign-in page (which also renders
 * the provider) does not download the whole content icon set for a dozen
 * glyphs.
 */

function glyph(...paths: string[]) {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {paths.map((d, index) => <path key={index} d={d} />)}
        </svg>
    );
}

const GLYPHS = {
    plus: glyph('M12 5v14', 'M5 12h14'),
    check: glyph('M20 6 9 17l-5-5'),
    x: glyph('M18 6 6 18', 'M6 6l12 12'),
    pencil: glyph('M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z'),
    trash: glyph('M3 6h18', 'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'),
    archive: glyph('M20 8v13H4V8', 'M2 3h20v5H2z', 'M10 12h4'),
    restore: glyph('M3 12a9 9 0 1 0 3-6.7', 'M3 4v5h5'),
    eye: glyph('M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z', 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'),
    search: glyph('M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z', 'm21 21-4.3-4.3'),
    filter: glyph('M22 3H2l8 9.5V19l4 2v-8.5z'),
    download: glyph('M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'M7 10l5 5 5-5', 'M12 15V3'),
    upload: glyph('M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'M17 8l-5-5-5 5', 'M12 3v12'),
    printer: glyph('M6 9V2h12v7', 'M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2', 'M6 14h12v8H6z'),
    arrowLeft: glyph('M19 12H5', 'M12 19l-7-7 7-7'),
    arrowRight: glyph('M5 12h14', 'M12 5l7 7-7 7'),
    logOut: glyph('M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M16 17l5-5-5-5', 'M21 12H9'),
    copy: glyph('M9 9h12v12H9z', 'M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1'),
    send: glyph('m22 2-7 20-4-9-9-4Z', 'M22 2 11 13'),
};

/** Translation keys per glyph; a key the dictionary lacks is skipped. */
const RULES: Array<[keyof typeof GLYPHS, string[]]> = [
    ['plus', ['common.create', 'common.add', 'common.addNew']],
    ['check', ['common.save', 'common.update', 'common.submit', 'common.confirm', 'common.yes', 'common.apply', 'common.approve', 'settings.saveChanges']],
    ['x', ['common.cancel', 'common.close', 'common.no', 'common.reject']],
    ['pencil', ['common.edit']],
    ['trash', ['common.delete', 'common.remove']],
    ['archive', ['common.archive']],
    ['restore', ['common.restore', 'common.reset', 'common.clear', 'common.refresh', 'common.retry']],
    ['eye', ['common.view', 'common.preview', 'common.details', 'common.open']],
    ['search', ['common.search']],
    ['filter', ['common.filter']],
    ['download', ['common.export', 'common.download']],
    ['upload', ['common.import', 'common.upload']],
    ['printer', ['common.print']],
    ['arrowLeft', ['common.back', 'common.previous']],
    ['arrowRight', ['common.next', 'common.continue']],
    ['logOut', ['common.signOut']],
    ['copy', ['common.copy']],
    ['send', ['common.send']],
];

/** The rules for the current language, rebuilt when the translator changes. */
export function useActionIconRules(t: (key: string) => string): ActionIconRule[] {
    return useMemo(() => RULES.map(([name, keys]) => ({
        icon: GLYPHS[name],
        // A missing key comes back as the key itself; it is not a label anyone sees.
        labels: keys.map((key) => t(key)).filter((label) => label && !label.includes('.')),
    })).filter((rule) => rule.labels.length > 0), [t]);
}
