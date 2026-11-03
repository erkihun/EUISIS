import { cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

/** A reference row's two names on one line of a table: the reader's language first, the other beneath it. */
export default function ClassificationName({ nameEn, nameAm, strong = false, plain = false }: { nameEn: string | null; nameAm: string | null; strong?: boolean; plain?: boolean }) {
    const { locale } = useLocale();
    const english = { text: nameEn, lang: 'en' };
    const amharic = { text: nameAm, lang: 'am' };
    const [primary, secondary] = locale === 'am' ? [amharic, english] : [english, amharic];
    const heading = primary.text ? primary : secondary;
    const caption = primary.text ? secondary : null;

    return (
        <div className="min-w-0">
            <div lang={heading.lang} className={cx('text-(--app-foreground)', strong ? 'font-semibold' : plain ? 'font-normal' : 'font-medium')}>
                {heading.text ?? '—'}
            </div>
            {caption?.text && (
                <div lang={caption.lang} className="mt-0.5 text-xs font-normal text-(--app-muted-foreground)">
                    {caption.text}
                </div>
            )}
        </div>
    );
}
