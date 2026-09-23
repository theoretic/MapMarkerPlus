import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';
import { engineResolve, engineEsbuild } from '@sla/map-engine/build/vite-preset';

/**
 * Builds the map scripts into assets/dist/ (committed, so sites need no node):
 *
 *   admin.js            InputfieldMapMarkerPlus editor, imported by InputfieldMapMarkerPlus.js
 *   frontend.js         <map-marker-plus> web component, used by MarkupMapMarkerPlus
 *   chunks/*.js         provider adapters, loaded on demand: a Google map never downloads MapLibre
 *   mapmarkerplus.css   MapLibre stylesheet, loaded by the MapLibre adapter
 *
 * Not a Vite "lib" build: lib mode does not minify whitespace of ES output. A multi-entry app build with
 * preserved entry signatures gives minified ES modules that keep their exports; base './' and no
 * modulepreload helper keep every chunk URL relative to the bundle, wherever the module is installed.
 *
 * The engine's i18n catalog is unused here but its alias must resolve.
 */

const localeFile = fileURLToPath(
  new URL('./node_modules/@sla/map-engine/src/i18n/messages/en.ts', import.meta.url),
);

export default defineConfig({
  base: './',
  publicDir: false,
  resolve: engineResolve(localeFile),
  esbuild: engineEsbuild(),
  build: {
    outDir: 'assets/dist',
    emptyOutDir: true,
    target: 'es2020',
    minify: 'terser',
    terserOptions: {
      compress: { passes: 2, ecma: 2020, module: true, pure_getters: true },
      format: { comments: false },
    },
    cssCodeSplit: false,
    modulePreload: false,
    rollupOptions: {
      input: {
        admin: fileURLToPath(new URL('./src/admin/index.ts', import.meta.url)),
        frontend: fileURLToPath(new URL('./src/frontend/index.ts', import.meta.url)),
      },
      preserveEntrySignatures: 'strict',
      output: {
        format: 'es',
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: 'mapmarkerplus.[ext]',
      },
    },
    chunkSizeWarningLimit: 1200,
  },
});
