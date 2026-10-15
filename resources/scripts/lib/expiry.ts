import { addDays, endOfDay, isValid, parseISO } from 'date-fns';

export type ExpiryPreset = 'never' | '7' | '30' | '90' | 'custom';

export const expiryPresets: { value: ExpiryPreset; label: string }[] = [
    { value: 'never', label: 'Never' },
    { value: '7', label: '7 days' },
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
    { value: 'custom', label: 'Custom date' },
];

/**
 * Returns when a key created now with the given preset should expire: null for keys that
 * never expire, or undefined when a custom date is missing, invalid or not in the future.
 * A custom date (YYYY-MM-DD) expires at the end of that day in the user's time zone.
 */
function expiryFromPreset(preset: ExpiryPreset, customDate = '', now: Date = new Date()): Date | null | undefined {
    switch (preset) {
        case 'never':
            return null;
        case '7':
        case '30':
        case '90':
            return addDays(now, Number(preset));
        case 'custom': {
            const date = parseISO(customDate);
            if (!/^\d{4}-\d{2}-\d{2}$/.test(customDate) || !isValid(date)) {
                return undefined;
            }

            const expiresAt = endOfDay(date);

            return expiresAt > now ? expiresAt : undefined;
        }
        default:
            return undefined;
    }
}

export { expiryFromPreset };
