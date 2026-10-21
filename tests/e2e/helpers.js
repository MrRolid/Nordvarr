import { expect } from '@playwright/test';

export const ADMIN_EMAIL = 'admin@example.com';
export const ADMIN_PASSWORD = 'e2e-tajne-heslo';

export async function login(page, password = ADMIN_PASSWORD) {
  await page.goto('/login');
  await page.getByLabel('E-mail').fill(ADMIN_EMAIL);
  await page.getByLabel('Heslo').fill(password);
  await page.getByRole('button', { name: 'Přihlásit se' }).click();
}

export async function loginOk(page) {
  await login(page);
  await expect(page.getByRole('heading', { level: 1, name: 'Přehled' })).toBeVisible();
}
