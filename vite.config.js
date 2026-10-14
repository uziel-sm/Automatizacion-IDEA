import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/build/' : '/',
  root: 'gui',
  plugins: [
    tailwindcss(),
  ],
  server: {
    port: 5173,
    open: true,
    allowedHosts: ['dottie-unsecure-ectally.ngrok-free.dev'],
    proxy: {
      '/api': 'http://localhost:8000',
    },
  },
  build: {
    outDir: path.resolve(__dirname, 'public/build'),
    emptyOutDir: true,
  }
}));