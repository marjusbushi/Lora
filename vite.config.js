import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { execFileSync } from 'node:child_process';

const buildId = (() => {
    const configuredId = process.env.VITE_BUILD_ID || process.env.GITHUB_SHA;

    if (configuredId) {
        return configuredId.slice(0, 12).replace(/[^a-zA-Z0-9_-]/g, '');
    }

    try {
        return execFileSync('git', ['rev-parse', '--short=12', 'HEAD'], {
            encoding: 'utf8',
        }).trim();
    } catch {
        return 'local';
    }
})();

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
    build: {
        // A chunk's content hash can stay unchanged even when only an imported
        // chunk filename changes. Adding the release id prevents a browser or
        // CDN from combining assets from two different deployments.
        emptyOutDir: true,
        rollupOptions: {
            output: {
                entryFileNames: `assets/[name]-[hash]-${buildId}.js`,
                chunkFileNames: `assets/[name]-[hash]-${buildId}.js`,
                assetFileNames: `assets/[name]-[hash]-${buildId}[extname]`,
            },
        },
    },
});
