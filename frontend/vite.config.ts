import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

const here = (p: string) => fileURLToPath(new URL(p, import.meta.url));

// The bundle is served from wp-content/plugins/imedix-knowledge-universe/assets/dist/.
// A relative base keeps lazy chunks (three.js) resolvable from wherever WordPress
// serves the plugin; PHP reads .vite/manifest.json to enqueue the hashed entry files.
export default defineConfig({
  base: './',
  build: {
    outDir: here('../wordpress/imedix-knowledge-universe/assets/dist'),
    emptyOutDir: true,
    manifest: true,
    target: 'es2020',
    cssCodeSplit: true,
    assetsInlineLimit: 0,
    // three.js lives in a lazily imported chunk (~142 kB gzip) loaded after first paint.
    chunkSizeWarningLimit: 650,
    rollupOptions: {
      input: { main: here('./src/main.ts') },
    },
  },
  server: { port: 5173, strictPort: true },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
  },
} as Parameters<typeof defineConfig>[0]);
