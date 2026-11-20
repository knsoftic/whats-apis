import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Brand colour chosen on Settings → Appearance (CSS variables set in the layout).
                brand: Object.fromEntries(
                    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((s) => [s, `rgb(var(--brand-${s}) / <alpha-value>)`]),
                ),
                chat: {
                    light: '#efeae2',
                    dark: '#0b141a',
                    outLight: '#d9fdd3',
                    outDark: '#005c4b',
                    inDark: '#202c33',
                },
            },
        },
    },

    plugins: [forms],
};
