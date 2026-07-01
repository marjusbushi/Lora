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
    // The POK card SDK is React-based (CJS). Pre-bundle it + React so the embedded form
    // renders in dev mode (Vite otherwise fails to resolve a newly-added CJS dep until restart).
    optimizeDeps: {
        include: ['@nebula-ltd/pok-payments-js', 'react', 'react-dom', 'react-dom/client'],
    },
});
