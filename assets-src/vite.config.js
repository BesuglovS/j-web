import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';

export default defineConfig({
  plugins: [tailwindcss()],
  root: fileURLToPath(new URL('.', import.meta.url)),
  build: {
    outDir: fileURLToPath(new URL('../public/assets', import.meta.url)),
    emptyOutDir: true,
    assetsDir: '.',
    rollupOptions: {
      input: fileURLToPath(new URL('entries/app.js', import.meta.url)),
      output: {
        entryFileNames: 'app.js',
        assetFileNames: 'app.css'
      }
    },
    cssMinify: true
  }
});