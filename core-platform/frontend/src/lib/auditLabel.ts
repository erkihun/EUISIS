/** The translated name of an audit action (card.suspend → platform.audit.actions.card_suspend), else the action in words. */
export function auditLabel(t: (key: string) => string, action: string): string {
    const key = `platform.audit.actions.${action.replace(/\./g, '_')}`;
    const translated = t(key);
    return translated === key ? action.replace(/[._-]/g, ' ') : translated;
}
