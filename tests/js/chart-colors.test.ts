import assert from 'node:assert/strict';
import { test } from 'node:test';

/*
 * The chart palette's contrast contract (docs/dashboard-design-system.md).
 *
 * Recharts defaults axis tick labels to a hardcoded `#666`. Measured against
 * the dark card surface (#111827) that is 3.09:1 — below the 4.5:1 WCAG AA
 * requires for small text — so axis labels were genuinely hard to read in dark
 * mode while the gridlines beside them were themed correctly. These tests pin
 * the replacement values so a future palette edit cannot quietly reintroduce a
 * failing contrast.
 *
 * Run:
 *   npx esbuild tests/js/chart-colors.test.ts --bundle --platform=node --format=cjs --outfile=storage/framework/testing/chart-colors.test.cjs
 *   node --test storage/framework/testing/chart-colors.test.cjs
 */

/** WCAG relative luminance. */
function luminance([r, g, b]: [number, number, number]): number {
    const a = [r, g, b].map((v) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });

    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
}

function hexToRgb(hex: string): [number, number, number] {
    return [
        parseInt(hex.slice(1, 3), 16),
        parseInt(hex.slice(3, 5), 16),
        parseInt(hex.slice(5, 7), 16),
    ];
}

function contrast(foreground: string, background: string): number {
    const [light, dark] = [luminance(hexToRgb(foreground)), luminance(hexToRgb(background))]
        .sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

/** Card surfaces the charts are drawn on, from the app's theme tokens. */
const DARK_SURFACE = '#111827';
const LIGHT_SURFACE = '#ffffff';

/** Must match readColors() in resources/js/hooks/useChartColors.ts. */
const AXIS_TEXT_DARK = '#cbd5e1';
const AXIS_TEXT_LIGHT = '#475569';

const AA_SMALL_TEXT = 4.5;

test('axis tick labels clear WCAG AA on the dark card surface', () => {
    const ratio = contrast(AXIS_TEXT_DARK, DARK_SURFACE);

    assert.ok(
        ratio >= AA_SMALL_TEXT,
        `axis text ${AXIS_TEXT_DARK} on ${DARK_SURFACE} is ${ratio.toFixed(2)}:1, below ${AA_SMALL_TEXT}:1`,
    );
});

test('axis tick labels clear WCAG AA on the light card surface', () => {
    const ratio = contrast(AXIS_TEXT_LIGHT, LIGHT_SURFACE);

    assert.ok(
        ratio >= AA_SMALL_TEXT,
        `axis text ${AXIS_TEXT_LIGHT} on ${LIGHT_SURFACE} is ${ratio.toFixed(2)}:1, below ${AA_SMALL_TEXT}:1`,
    );
});

test("Recharts' default tick colour is the thing we are guarding against", () => {
    // Documents why the override exists: the default fails on dark, which is
    // exactly the regression this file catches.
    assert.ok(
        contrast('#666666', DARK_SURFACE) < AA_SMALL_TEXT,
        'if the Recharts default now passes, this guard can be revisited',
    );
});
