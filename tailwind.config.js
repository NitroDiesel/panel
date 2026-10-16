const colors = require('tailwindcss/colors');

/**
 * Colors that themes may change are CSS variables holding comma-separated channels
 * (HSL for the gray ramp, RGB for the primary ramp and black). Their defaults live in
 * resources/scripts/assets/tailwind.css and match the values this file used to
 * hard-code, so the default look is unchanged.
 */
const variable = (fn, name) => ({ opacityVariable, opacityValue }) => {
    if (opacityValue !== undefined) {
        return `${fn}a(var(${name}), ${opacityValue})`;
    }

    return opacityVariable !== undefined ? `${fn}a(var(${name}), var(${opacityVariable}, 1))` : `${fn}(var(${name}))`;
};

const ramp = (fn, name) =>
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900].reduce(
        (scale, step) => ({ ...scale, [step]: variable(fn, `--color-${name}-${step}`) }),
        {},
    );

const gray = ramp('hsl', 'gray');

module.exports = {
    content: [
        './resources/scripts/**/*.{js,ts,tsx}',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['var(--font-sans)'],
                header: ['var(--font-header)'],
            },
            colors: {
                black: variable('rgb', '--color-black'),
                // "primary" and "neutral" are deprecated, prefer the use of "blue" and "gray"
                // in new code.
                primary: ramp('rgb', 'primary'),
                gray: gray,
                neutral: gray,
                cyan: colors.cyan,
            },
            fontSize: {
                '2xs': '0.625rem',
            },
            transitionDuration: {
                250: '250ms',
            },
            borderColor: theme => ({
                default: theme('colors.neutral.400', 'currentColor'),
            }),
        },
    },
    plugins: [
        require('@tailwindcss/line-clamp'),
        require('@tailwindcss/forms')({
            strategy: 'class',
        }),
    ]
};
