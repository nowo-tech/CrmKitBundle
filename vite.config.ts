import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Library IIFE for `assets:install` (REQ-ASSETS-001 / REQ-ASSETS-004).
 * Output: `src/Resources/public/js/nowo-crm-import.js`, served through the `nowo_crm_kit`
 * asset package (`asset('js/nowo-crm-import.js', 'nowo_crm_kit')`).
 */
export default defineConfig({
  build: {
    outDir: 'src/Resources/public/js',
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'src/Resources/assets/src/iife.ts'),
      name: 'NowoCrmImport',
      formats: ['iife'],
      fileName: () => 'nowo-crm-import.js',
    },
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
      },
    },
    minify: true,
    sourcemap: false,
    target: 'es2022',
  },
});
