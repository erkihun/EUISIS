/**
 * Writes the physical print density into a PNG.
 *
 * A PNG is a bag of pixels: nothing in the file says how big those pixels are
 * on paper unless a `pHYs` chunk says so. Without one, Word, Acrobat and the
 * Windows photo printer each assume their own default (usually 96 DPI), so a
 * card exported at 1011 px prints about 267 mm wide instead of 85.6 mm.
 *
 * `pHYs` carries pixels-per-metre for both axes plus a unit byte, so a viewer
 * can work back to the true physical size. We write it once, after IHDR.
 */

const PNG_SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];
const INCH_IN_METRES = 0.0254;

/** CRC-32 as PNG specifies it, over chunk type + data. */
const CRC_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        table[n] = c >>> 0;
    }
    return table;
})();

function crc32(bytes: Uint8Array): number {
    let c = 0xffffffff;
    for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
}

function writeUint32(view: Uint8Array, offset: number, value: number): void {
    view[offset] = (value >>> 24) & 0xff;
    view[offset + 1] = (value >>> 16) & 0xff;
    view[offset + 2] = (value >>> 8) & 0xff;
    view[offset + 3] = value & 0xff;
}

function dataUrlToBytes(dataUrl: string): Uint8Array | null {
    const comma = dataUrl.indexOf(',');
    if (comma < 0 || !dataUrl.slice(0, comma).includes('base64')) return null;
    const binary = atob(dataUrl.slice(comma + 1));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
}

function bytesToDataUrl(bytes: Uint8Array): string {
    let binary = '';
    // Chunked so a large card does not blow the argument limit.
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }
    return 'data:image/png;base64,' + btoa(binary);
}

/**
 * Returns the PNG data URL with a `pHYs` chunk declaring `dpi`, so printing it
 * yields the card's true physical size. Any existing `pHYs` is replaced.
 *
 * Returns the input unchanged if it is not a base64 PNG — an export that
 * prints at the wrong size is better than an export that fails.
 */
export function withPngDensity(dataUrl: string, dpi: number): string {
    const bytes = dataUrlToBytes(dataUrl);
    if (bytes === null || PNG_SIGNATURE.some((b, i) => bytes[i] !== b)) return dataUrl;

    const perMetre = Math.round(dpi / INCH_IN_METRES);
    const chunk = new Uint8Array(21); // length(4) + type(4) + data(9) + crc(4)
    writeUint32(chunk, 0, 9);
    chunk.set([0x70, 0x48, 0x59, 0x73], 4); // 'pHYs'
    writeUint32(chunk, 8, perMetre);
    writeUint32(chunk, 12, perMetre);
    chunk[16] = 1; // unit: metre
    writeUint32(chunk, 17, crc32(chunk.subarray(4, 17)));

    // Walk the chunks so the new one lands right after IHDR, and any existing
    // pHYs is dropped rather than duplicated.
    const out: Uint8Array[] = [bytes.subarray(0, 8)];
    let offset = 8;
    let inserted = false;

    while (offset + 8 <= bytes.length) {
        const length = (bytes[offset] << 24) | (bytes[offset + 1] << 16) | (bytes[offset + 2] << 8) | bytes[offset + 3];
        const type = String.fromCharCode(bytes[offset + 4], bytes[offset + 5], bytes[offset + 6], bytes[offset + 7]);
        const end = offset + 12 + length;
        if (length < 0 || end > bytes.length) break;

        if (type !== 'pHYs') out.push(bytes.subarray(offset, end));
        if (type === 'IHDR' && !inserted) {
            out.push(chunk);
            inserted = true;
        }
        offset = end;
        if (type === 'IEND') break;
    }

    if (!inserted) return dataUrl;

    const total = out.reduce((sum, part) => sum + part.length, 0);
    const merged = new Uint8Array(total);
    let cursor = 0;
    for (const part of out) {
        merged.set(part, cursor);
        cursor += part.length;
    }

    return bytesToDataUrl(merged);
}

/** Pixels needed to print `mm` at `dpi`. */
export function pixelsForMillimetres(mm: number, dpi: number): number {
    return Math.max(1, Math.round((mm / 25.4) * dpi));
}
