/**
 * Converts any date/datetime string to the "yyyy-MM-dd" format required by
 * <input type="date">. Returns empty string for null/undefined/invalid input.
 */
export function toDateInput(value: string | null | undefined): string {
    if (!value) return '';
    // Already in yyyy-MM-dd format
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    // ISO datetime — take only the date portion before 'T'
    const datePart = value.split('T')[0];
    if (/^\d{4}-\d{2}-\d{2}$/.test(datePart)) return datePart;
    // Fallback: try parsing as a Date
    try {
        const d = new Date(value);
        if (!isNaN(d.getTime())) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }
    } catch {}
    return '';
}
