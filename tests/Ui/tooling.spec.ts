import { expect, test } from '@playwright/test';

test('browser test surface is executable', async ({ page }) => {
  await page.setContent('<main data-walleting-test="ready">Walleting</main>');

  await expect(page.locator('[data-walleting-test="ready"]')).toHaveText('Walleting');
});
