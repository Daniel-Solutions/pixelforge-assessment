const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const integer = new Intl.NumberFormat();

/** UTC timestamps from the backend, shown in the browser's time zone. */
export function formatDateTime(iso: string): string {
    return dateTime.format(new Date(iso));
}

export function formatTokens(tokens: number): string {
    return integer.format(tokens);
}
