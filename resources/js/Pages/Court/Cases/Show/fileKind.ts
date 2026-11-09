/** A file's kind as people name it ("PDF", "Word", "JPG"), not its MIME type. */
export function fileKind(mime: string | null | undefined): string | null {
    if (!mime) return null;
    if (mime === 'application/pdf') return 'PDF';
    if (/msword|wordprocessing/.test(mime)) return 'Word';
    if (/sheet|excel/.test(mime)) return 'Excel';
    if (mime.startsWith('image/')) return mime.slice(6).replace('jpeg', 'jpg').toUpperCase();

    return mime.split('/').pop()?.toUpperCase() ?? mime;
}
