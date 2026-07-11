/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// https://vite.dev/config/
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { storybookTest } from '@storybook/addon-vitest/vitest-plugin';
import { playwright } from '@vitest/browser-playwright';
import tailwindcss from '@tailwindcss/vite';
const dirname = typeof __dirname !== 'undefined' ? __dirname : path.dirname(fileURLToPath(import.meta.url));

// More info at: https://storybook.js.org/docs/next/writing-tests/integrations/vitest-addon
export default defineConfig({
  plugins: [react(), tailwindcss()],
  test: {
    projects: [{
      extends: true,
      test: {
        name: 'unit',
        environment: 'jsdom',
        setupFiles: ['./vitest.setup.ts'],
        include: ['src/**/*.test.{ts,tsx}'],
      }
    }, {
      extends: true,
      plugins: [
      // The plugin will run tests for the stories defined in your Storybook config
      // See options at: https://storybook.js.org/docs/next/writing-tests/integrations/vitest-addon#storybooktest
      storybookTest({
        configDir: path.join(dirname, '.storybook')
      })],
      test: {
        name: 'storybook',
        browser: {
          enabled: true,
          headless: true,
          // e2e/playwright.config.ts と同様、環境にインストール済みのPlaywright版と
          // vitestが期待するブラウザのリビジョンが一致しない場合に既存バイナリを直接
          // 指定できるようにする(未設定時は従来通りvitestにダウンロード管理させる)。
          provider: playwright({
            launchOptions: process.env.VITEST_CHROMIUM_EXECUTABLE_PATH
              ? { executablePath: process.env.VITEST_CHROMIUM_EXECUTABLE_PATH }
              : {},
          }),
          instances: [{
            browser: 'chromium'
          }]
        }
      }
    }]
  }
});