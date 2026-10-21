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
