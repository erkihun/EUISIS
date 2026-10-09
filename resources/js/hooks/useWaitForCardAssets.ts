/**
 * Helpers to wait for images and web fonts to finish loading before
 * a capture target is rasterised by html-to-image / html2canvas.
 *
 * Without these waits the exported PNG can be missing the photo, the
 * org logo or the QR/text rendered with the Ethiopic web font.
 */

/** Resolves once every <img> inside `container` is fully decoded (or has errored). */
export async function waitForImages(container: HTMLElement): Promise<void> {
    const images = Array.from(container.querySelectorAll('img'));
    await Promise.all(
        images.map(
            (img) =>
                new Promise<void>((resolve) => {
                    if (img.complete) {
                        resolve();
                        return;
                    }
                    img.onload = () => resolve();
                    // Don't block capture on broken images — placeholders are
                    // acceptable in the output.
                    img.onerror = () => resolve();
                }),
        ),
    );
}

/** Resolves once the browser reports all web fonts are loaded. */
export async function waitForFonts(): Promise<void> {
    if (typeof document !== 'undefined' && document.fonts?.ready) {
        try {
            await document.fonts.ready;
        } catch {
            // Some browsers reject fonts.ready when fonts fail to load — ignore.
        }
    }
}

/**
 * Wait for both fonts and any images inside `container` to be ready.
 * Adds a single rAF tick after the waits resolve so style/layout is settled.
 */
export async function waitForCardAssets(container: HTMLElement): Promise<void> {
    await Promise.all([waitForFonts(), waitForImages(container)]);
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));
}
