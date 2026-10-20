import { test, expect } from '@playwright/test';
import { ADMIN_EMAIL, login, loginOk } from './helpers.js';

test('nepřihlášený uživatel skončí na přihlášení', async ({ page }) => {
  await page.goto('/settings');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('heading', { name: 'Přihlášení' })).toBeVisible();
});

test('špatné heslo vrátí obecnou hlášku', async ({ page }) => {
  await login(page, 'uplne-spatne-heslo');
  await expect(page.getByTestId('login-error')).toHaveText('Nesprávný e-mail nebo heslo.');
});

test('přihlášení, navigace a odhlášení', async ({ page }) => {
  await loginOk(page);
  await expect(page.getByText(`Uživatel: ${ADMIN_EMAIL}`)).toBeVisible();

  const nav = page.getByRole('navigation', { name: 'Hlavní navigace' });
  await nav.getByRole('link', { name: 'Inventář' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Inventář' })).toBeVisible();
  await expect(nav.getByRole('link', { name: 'Inventář' })).toHaveAttribute('aria-current', 'page');

  await page.getByTestId('logout').click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByText('Odhlášení proběhlo.')).toBeVisible();

  await page.goto('/');
  await expect(page).toHaveURL(/\/login$/);
});

test('fallback adresy index.php?r= fungují i bez mod_rewrite', async ({ page }) => {
  await page.goto('/index.php?r=/login');
  await expect(page.getByRole('heading', { name: 'Přihlášení' })).toBeVisible();
});

test('přepnutí do angličtiny a zpět', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Jazyk').selectOption('en');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await page.getByLabel('Language').selectOption('cs');
  await expect(page.getByRole('heading', { name: 'Přihlášení' })).toBeVisible();
});

test('bezpečnostní hlavičky a žádné chyby CSP', async ({ page, context }) => {
  const cspErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') cspErrors.push(msg.text());
  });
  const response = await page.goto('/login');
  const headers = response.headers();
  expect(headers['content-security-policy']).toContain("script-src 'self'");
  expect(headers['x-frame-options']).toBe('DENY');
  expect(headers['referrer-policy']).toBe('same-origin');
  expect(headers['x-content-type-options']).toBe('nosniff');
  const session = (await context.cookies()).find((c) => c.name === 'NORDVARRSID');
  expect(session).toBeTruthy();
  expect(session.httpOnly).toBe(true);
  expect(session.sameSite).toBe('Lax');
  // ES modul se načetl a označil dokument.
  await expect(page.locator('html')).toHaveClass(/\bjs\b/);
  expect(cspErrors).toEqual([]);
});
