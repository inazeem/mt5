import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                surface: {
                    DEFAULT: '#ffffff',
                    muted: '#f8fafc',
                    border: '#e2e8f0',
                },
                accent: {
                    DEFAULT: '#4f46e5',
                    hover: '#4338ca',
                    muted: '#eef2ff',
                },
            },
        },
    },

    plugins: [forms],
};
