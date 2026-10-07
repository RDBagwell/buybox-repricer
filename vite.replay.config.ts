import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { defineConfig } from 'vite-plus';

/**
 * Static build of Replay mode for GitHub Pages: `npm run build:replay` → dist-replay/.
 * No Laravel, Inertia or Wayfinder involved; the recording is copied next to the page.
 */
export default defineConfig({
    base: './',
    publicDir: false,
    plugins: [react(), tailwindcss()],
    resolve: { alias: { '@': resolve(__dirname, 'resources/js') } },
    build: {
        outDir: 'dist-replay',
        emptyOutDir: true,
        rollupOptions: { input: resolve(__dirname, 'replay.html') },
    },
});
