/**
 * Vite Configuration for Therapy Chat React Component
 * ====================================================
 * 
 * Builds the React chat component as a UMD bundle for SelfHelp CMS.
 * Includes both TherapyChat (subject) and TherapistDashboard components.
 */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  
  define: {
    'process.env': {},
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
  },
  
  build: {
    lib: {
      entry: resolve(__dirname, 'src/TherapyChat.tsx'),
      name: 'TherapyChat',
      fileName: (format) => `therapy-chat.${format}.js`,
      formats: ['umd'],
    },
    
    rollupOptions: {
      output: {
        name: 'TherapyChat',
        format: 'umd',
        inlineDynamicImports: true,
        entryFileNames: 'therapy-chat.umd.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name === 'style.css') {
            return 'therapy-chat.css';
          }
          return assetInfo.name || 'assets/[name][extname]';
        },
      },
    },
    
    outDir: '../js/ext',
    emptyOutDir: false,
    sourcemap: false,
    minify: 'terser',
    
    terserOptions: {
      compress: {
        drop_console: false,
        drop_debugger: true,
      },
      format: {
        comments: false,
      },
    },
    
    cssCodeSplit: false,
  },
  
  server: {
    port: 3001,
    proxy: {
      '/index.php': {
        target: 'http://localhost/selfhelp',
        changeOrigin: true,
      },
    },
  },
  
  css: {
    modules: {
      localsConvention: 'camelCase',
    },
  },
  
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
});
