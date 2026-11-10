import { mutedCls, secondaryBtn, useLetters } from '@/Components/letters/helpers';
import { cx } from '@/Components/ui';
import { useRef, useState, type JSX } from 'react';

/**
 * A file chooser that speaks the page's language: the browser's own control
 * always reads "Choose file / No file chosen" in the browser's language, so it
 * stays in the page for assistive technology but is hidden behind a button
 * and the chosen names.
 */
export default function FilePicker({ id, accept, multiple, onChange, describedBy }: {
    id: string; accept: string; multiple?: boolean; onChange: (files: File[]) => void; describedBy?: string;
}): JSX.Element {
    const { lt } = useLetters();
    const input = useRef<HTMLInputElement>(null);
    const [names, setNames] = useState<string[]>([]);

    return (
        <div className="flex flex-wrap items-center gap-3">
            <input ref={input} id={id} type="file" accept={accept} multiple={multiple} className="sr-only" aria-describedby={describedBy}
                onChange={(e) => { const files = Array.from(e.target.files ?? []); setNames(files.map((f) => f.name)); onChange(files); }} />
            <button type="button" className={secondaryBtn} onClick={() => input.current?.click()}>{lt(multiple ? 'show.chooseFiles' : 'show.chooseFile')}</button>
            <span className={cx(mutedCls, 'min-w-0 truncate')} aria-live="polite" title={names.join(', ')}>
                {names.length === 0 ? lt('show.noFileChosen') : names.length === 1 ? names[0] : lt('show.filesChosen', { count: names.length })}
            </span>
        </div>
    );
}
