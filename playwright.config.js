// Playwright: hlavní průchody UI nad balíčkem sestaveným bin/build-release.sh.
import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.E2E_PORT || 8765);

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.js',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
    testIdAttribute: 'data-test',
  },
  projects: [
    {
      name: 'instalace',
      testMatch: /install\.spec\.js/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'desktop',
      testMatch: /(app|inventory)\.spec\.js/,
      dependencies: ['instalace'],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobil',
      testMatch: /mobile\.spec\.js/,
      dependencies: ['desktop'],
      use: { ...devices['Pixel 7'] },
    },
  ],
});
