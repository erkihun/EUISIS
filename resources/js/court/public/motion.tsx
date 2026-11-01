import { useEffect, useRef, useState, type CSSProperties, type RefObject } from 'react';

/*
 * The public site's motion (resources/css/court-public.css, "Motion"): one
 * calm language for the whole site, taken from the staff sign-in. Things rise
 * into place as they come into view, figures count up, the court's seal draws
 * itself and its scales settle level, and a brass light marks progress. Only
 * transform and opacity move. Nothing moves when the "UI animations" setting
 * is off (data-motion="off" on the layout root) or the visitor asks for
 * reduced motion; then everything is simply there.
 */

const REDUCED = '(prefers-reduced-motion: reduce)';

/** Whether this page may move: the layout's setting and the visitor's preference. */
export function motionAllowed(element: Element | null): boolean {
    if (typeof window === 'undefined' || window.matchMedia(REDUCED).matches) return false;
    return element?.closest('.court-site')?.getAttribute('data-motion') !== 'off';
}

/** A stagger index for an item in a list (`--i`), read by the reveal transition's delay. */
export function stagger(index: number): CSSProperties {
    return { '--i': Math.min(index, 8) } as CSSProperties;
}

/**
 * Reveals every `[data-reveal]` under the root as it scrolls into view (it
 * gets `data-revealed`). The root carries `data-reveal-ready` only once this
 * runs, so content is never hidden if it does not. Elements added later (a
 * new slide, a filtered list) are watched too.
 */
export function useReveal(root: RefObject<HTMLElement | null>): void {
    useEffect(() => {
        const element = root.current;
        if (!element) return undefined;
        if (!motionAllowed(element) || typeof IntersectionObserver === 'undefined') {
            element.removeAttribute('data-reveal-ready');
            return undefined;
        }

        const observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                entry.target.setAttribute('data-revealed', '');
                observer.unobserve(entry.target);
            }
        // Any part on screen is enough: a tall list (two weeks of sittings) must never wait for a share of itself.
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0 });

        const watch = (scope: ParentNode) => {
            scope.querySelectorAll('[data-reveal]:not([data-revealed])').forEach((node) => observer.observe(node));
        };
        watch(element);
        element.setAttribute('data-reveal-ready', '');

        const mutations = new MutationObserver((records) => {
            for (const record of records) {
                record.addedNodes.forEach((node) => {
                    if (!(node instanceof Element)) return;
                    if (node.matches('[data-reveal]:not([data-revealed])')) observer.observe(node);
                    watch(node);
                });
            }
        });
        mutations.observe(element, { childList: true, subtree: true });

        return () => {
            observer.disconnect();
            mutations.disconnect();
        };
    }, [root]);
}

/** Sets `data-scrolled` on the header and the brass reading-progress bar's `--progress` as the page scrolls. */
export function useScrollProgress(header: RefObject<HTMLElement | null>): void {
    useEffect(() => {
        const element = header.current;
        if (!element) return undefined;
        let frame = 0;
        const update = () => {
            frame = 0;
            const max = document.documentElement.scrollHeight - window.innerHeight;
            const y = window.scrollY;
            element.style.setProperty('--progress', max > 0 ? String(Math.min(1, y / max)) : '0');
            element.toggleAttribute('data-scrolled', y > 8);
        };
        const onScroll = () => { if (!frame) frame = window.requestAnimationFrame(update); };
        update();
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
            if (frame) window.cancelAnimationFrame(frame);
        };
    }, [header]);
}

const easeOut = (t: number) => 1 - (1 - t) ** 3;

/**
 * A figure that counts up from zero the first time it is seen. Screen readers
 * get the final figure at once; the moving digits are hidden from them.
 */
export function CountUp({ value, format, duration = 1600, className }: {
    value: number;
    format: (value: number) => string;
    duration?: number;
    className?: string;
}) {
    const ref = useRef<HTMLSpanElement>(null);
    const [shown, setShown] = useState<number | null>(null);

    useEffect(() => {
        const element = ref.current;
        if (!element || !motionAllowed(element) || typeof IntersectionObserver === 'undefined' || value <= 0) {
            setShown(null);
            return undefined;
        }
        setShown(0);
        let frame = 0;
        const observer = new IntersectionObserver(([entry]) => {
            if (!entry?.isIntersecting) return;
            observer.disconnect();
            const start = performance.now();
            const tick = (now: number) => {
                const progress = Math.min(1, (now - start) / duration);
                setShown(Math.round(value * easeOut(progress)));
                if (progress < 1) frame = window.requestAnimationFrame(tick);
            };
            frame = window.requestAnimationFrame(tick);
        }, { threshold: 0.4 });
        observer.observe(element);
        return () => {
            observer.disconnect();
            if (frame) window.cancelAnimationFrame(frame);
        };
    }, [value, duration]);

    return (
        <span ref={ref} className={className}>
            <span aria-hidden="true">{format(shown ?? value)}</span>
            <span className="sr-only">{format(value)}</span>
        </span>
    );
}

/** A heading whose words rise one after another (the hero's statement). Spaces stay text, so it wraps as before. */
export function RisingWords({ text }: { text: string }) {
    let word = 0;
    return (
        <>
            {text.split(/(\s+)/).map((part, index) => (/^\s+$/.test(part) || part === ''
                ? part
                : <span key={index} className="court-word" style={{ '--w': word++ } as CSSProperties}>{part}</span>))}
        </>
    );
}

/** The court's seal, large and faint: dotted rings turning about the scales, which draw in and settle level. */
export function CourtSeal({ className = '' }: { className?: string }) {
    return (
        <div aria-hidden="true" className={`court-seal pointer-events-none ${className}`}>
            <span className="court-seal-glow" />
            <svg viewBox="0 0 200 200" fill="none" className="court-seal-ring">
                <circle cx="100" cy="100" r="97" stroke="currentColor" strokeWidth="1.2" strokeDasharray="0.5 6" strokeLinecap="round" />
                <circle cx="100" cy="100" r="91" stroke="currentColor" strokeWidth="0.4" />
            </svg>
            <svg viewBox="0 0 200 200" fill="none" className="court-seal-ring court-seal-ring-reverse">
                <circle cx="100" cy="100" r="80" stroke="currentColor" strokeWidth="4" strokeDasharray="0.6 9.45" />
                <circle cx="100" cy="100" r="73" stroke="currentColor" strokeWidth="0.4" strokeDasharray="14 6" />
            </svg>
            <svg viewBox="0 0 64 64" fill="none" className="court-seal-scales">
                <path d="M32 14v34M22 50h20M26 50l6-4 6 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" pathLength={100} className="court-seal-draw" />
                <circle cx="32" cy="13" r="1.8" fill="currentColor" className="court-seal-fade" />
                <g className="court-seal-beam">
                    <path d="M17 21h30" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" pathLength={100} className="court-seal-draw court-seal-d1" />
                    <g className="court-seal-pan">
                        <path d="M19 21l-5 11M19 21l5 11" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" pathLength={100} className="court-seal-draw court-seal-d2" />
                        <path d="M12.5 32a6.5 3.2 0 0 0 13 0z" fill="currentColor" className="court-seal-fade court-seal-d3" />
                    </g>
                    <g className="court-seal-pan court-seal-pan-right">
                        <path d="M45 21l-5 11M45 21l5 11" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" pathLength={100} className="court-seal-draw court-seal-d2" />
                        <path d="M38.5 32a6.5 3.2 0 0 0 13 0z" fill="currentColor" className="court-seal-fade court-seal-d3" />
                    </g>
                </g>
            </svg>
        </div>
    );
}

/** Brass motes rising slowly through the hero, as dust in the light of a hall. */
export function CourtMotes({ count = 14 }: { count?: number }) {
    return (
        <div aria-hidden="true" className="court-motes pointer-events-none">
            {Array.from({ length: count }).map((_, i) => (
                <span key={i} style={{ '--x': `${(i * 37 + 11) % 100}%`, '--d': `${9 + ((i * 7) % 9)}s`, '--t': `${-((i * 1.7) % 12)}s`, '--s': `${2 + (i % 3)}px` } as CSSProperties} />
            ))}
        </div>
    );
}
