/**
 * Given a valid six character HEX color code, converts it into its associated
 * RGBA value with a user controllable alpha channel.
 */
function hexToRgba(hex: string, alpha = 1): string {
    // noinspection RegExpSimplifiable
    if (!/#?([a-fA-F0-9]{2}){3}/.test(hex)) {
        return hex;
    }

    // noinspection RegExpSimplifiable
    const [r, g, b] = hex.match(/[a-fA-F0-9]{2}/g)!.map((v) => parseInt(v, 16));

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Returns the value of a CSS custom property set on the document root, for code
 * that cannot use the variable directly, such as colors drawn on a canvas.
 */
function cssVariable(name: string, fallback = ''): string {
    if (typeof document === 'undefined' || typeof getComputedStyle === 'undefined') {
        return fallback;
    }

    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

export { hexToRgba, cssVariable };
