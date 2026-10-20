import { test, expect } from '@playwright/test';
import { loginOk } from './helpers.js';

test('na mobilu je hlavní navigace spodní lišta', async ({ page }) => {
  await loginOk(page);
  const nav = page.getByRole('navigation', { name: 'Hlavní navigace' });
  await expect(nav).toBeVisible();

  const viewport = page.viewportSize();
  const box = await nav.boundingBox();
  expect(box).not.toBeNull();
  // Lišta je přilepená ke spodnímu okraji a přes celou šířku.
  expect(Math.round(box.y + box.height)).toBe(viewport.height);
  expect(Math.round(box.width)).toBe(viewport.width);
  expect(await nav.evaluate((el) => getComputedStyle(el).position)).toBe('fixed');

  // Všech 5 položek je vidět a jde na ně kliknout.
  const links = nav.getByRole('link');
  await expect(links).toHaveCount(5);
  await nav.getByRole('link', { name: 'Alerty' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Alerty' })).toBeVisible();

  // Stránka nemá vodorovný posuvník.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('inventář a detail objektu jsou na mobilu bez vodorovného posuvníku', async ({ page }) => {
  await loginOk(page);
  await page.goto('/inventory');
  await expect(page.getByTestId('inventory-table')).toBeVisible();
  for (const path of ['/inventory', '/settings']) {
    await page.goto(path);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, path).toBeLessThanOrEqual(0);
  }
  await page.goto('/inventory');
  await page.getByTestId('inventory-table').getByRole('link').first().click();
  await expect(page.getByTestId('object-name')).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('přehled je na mobilu bez vodorovného posuvníku', async ({ page }) => {
  await loginOk(page);
  await page.goto('/');
  await expect(page.getByTestId('summary')).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('alerty jsou na mobilu bez vodorovného posuvníku', async ({ page }) => {
  await loginOk(page);
  await page.goto('/alerts');
  await expect(page.getByTestId('alerts')).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
