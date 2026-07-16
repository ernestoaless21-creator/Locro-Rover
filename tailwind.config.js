import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Fase 18 / Fase 0 del tema oscuro: alias de los colores que YA
            // quedaron implementados en Welcome.vue / Auth/Login.vue (no es
            // una paleta nueva). Un solo lugar para dejar de repetir
            // bg-gray-900/800/700, red-700/900, green-600, yellow-700
            // sueltos en decenas de archivos.
            colors: {
                ink: '#111827',           // gray-900 — fondo de pagina
                surface: '#1f2937',       // gray-800 — tarjetas, inputs, tabla
                'surface-2': '#273244',   // header de tabla, elevado
                'surface-3': '#374151',   // badges neutros, hover
                border: '#374151',        // gray-700 — borde de tarjeta
                'border-soft': '#4b5563', // gray-600 — borde de input
                ember: '#b91c1c',         // red-700 — accion principal
                'ember-strong': '#dc2626',// red-600 — hover
                'ember-wash': '#2c1414',  // fondo de foco / chips
                herb: '#16a34a',          // green-600 — exito / confirmar
                garnet: '#7f1d1d',        // red-900 — eliminar
                maize: '#a16207',         // yellow-700 — advertencia / parcial
            },
        },
    },

    plugins: [forms, typography],
};
