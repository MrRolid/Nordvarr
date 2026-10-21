import { test, expect } from '@playwright/test';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { ADMIN_EMAIL, ADMIN_PASSWORD } from './helpers.js';

test('čistá instalace z rozbaleného zipu přes install.php', async ({ page }) => {
  const site = process.env.E2E_SITE;
  expect(site, 'globalSetup nastavil E2E_SITE').toBeTruthy();
  expect(existsSync(join(site, 'nordvarr/config.php'))).toBe(false);

  // Bez config.php přesměruje aplikace na instalátor.
  await page.goto('/');
  await expect(page).toHaveURL(/install\.php/);
  await expect(page.getByRole('heading', { name: 'Instalace Nordvarru' })).toBeVisible();

  const dataDir = page.getByLabel('Datový adresář');
  await expect(dataDir).toHaveValue(join(site, 'nordvarr-data'));

  await page.getByLabel('E-mail administrátora').fill(ADMIN_EMAIL);
  await page.getByLabel('Heslo administrátora').fill(ADMIN_PASSWORD);
  await page.getByLabel('Heslo znovu').fill('jine-heslo-123');
  await page.getByRole('button', { name: 'Nainstalovat' }).click();
  await expect(page.getByText('Hesla se neshodují.')).toBeVisible();

  await page.getByLabel('Heslo administrátora').fill(ADMIN_PASSWORD);
  await page.getByLabel('Heslo znovu').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Nainstalovat' }).click();

  await expect(page.getByTestId('install-done')).toBeVisible();
  await expect(page.getByTestId('cron-line')).toHaveText(
    `* * * * * php ${join(site, 'nordvarr/bin/nordvarr.php')} worker >/dev/null 2>&1`,
  );
  await expect(page.getByText('Datový adresář leží mimo webroot.')).toBeVisible();
  expect(existsSync(join(site, 'nordvarr/config.php'))).toBe(true);
  expect(existsSync(join(site, 'nordvarr-data/nordvarr.sqlite'))).toBe(true);
  expect(existsSync(join(site, 'nordvarr-data/.htaccess'))).toBe(true);

  // Instalátor se zablokoval.
  const locked = await page.goto('/install.php');
  expect(locked.status()).toBe(403);
  await expect(page.getByTestId('install-locked')).toBeVisible();
});
