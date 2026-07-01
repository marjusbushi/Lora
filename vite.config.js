import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    // The POK card SDK is React-based (CJS) and references process.env + Node globals that
    // don't exist in the browser (undefined → the SDK throws → its generic "GENERAL_ERROR").
    // Define them so the SDK runs inside our Vite/Vue page the same as on POK's own page.
    define: {
        'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
        global: 'globalThis',
    },
    optimizeDeps: {
        include: ['@nebula-ltd/pok-payments-js', 'react', 'react-dom', 'react-dom/client'],
    },
});
