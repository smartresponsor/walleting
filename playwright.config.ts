import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/Ui',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  reporter: 'list',
  use: {
    browserName: 'chromium',
    headless: true,
  },
});
