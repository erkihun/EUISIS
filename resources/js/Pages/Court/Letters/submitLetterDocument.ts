type FieldValue = string | number | boolean | null | undefined;

/**
 * Saves a letter the way the Blade pages did: a classic form post with
 * target="_blank". The saved letter is a printable document page
 * (letters.show, Blade), so it opens in its own tab and this page keeps the
 * draft; a refused save brings the form back in that tab with the errors and
 * the submitted values (the controllers read old input into the props).
 */
export function submitLetterDocument(action: string, method: 'POST' | 'PATCH', values: Record<string, FieldValue>): void {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const form = document.createElement('form');
    form.method = 'post';
    form.action = action;
    form.target = '_blank';
    form.rel = 'noopener';
    form.hidden = true;

    const add = (name: string, value: FieldValue): void => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value === null || value === undefined ? '' : typeof value === 'boolean' ? (value ? '1' : '0') : String(value);
        form.appendChild(input);
    };

    add('_token', token);
    if (method !== 'POST') add('_method', method);
    Object.entries(values).forEach(([name, value]) => add(name, value));

    document.body.appendChild(form);
    form.submit();
    form.remove();
}
