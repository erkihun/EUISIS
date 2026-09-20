import { Component, type ReactNode } from 'react';

/**
 * Contains any failure from the lazily-loaded QR scanner.
 *
 * Written as a class because React error boundaries have no hook equivalent.
 * A chunk-load failure or an unsupported camera API must not blank the page —
 * manual entry is always a complete alternative path.
 *
 * Shared by the Verify and ID Checker pages, which previously each carried an
 * identical copy.
 */
export default class ScannerBoundary extends Component<
    { children: ReactNode; onFailure: () => void; fallbackLabel: string },
    { failed: boolean }
> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch() {
        this.props.onFailure();
    }

    render() {
        if (this.state.failed) {
            return (
                <p role="alert" className="rounded-card border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    {this.props.fallbackLabel}
                </p>
            );
        }

        return this.props.children;
    }
}
