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

  // Všech 6 položek je vidět a jde na ně kliknout.
  const links = nav.getByRole('link');
  await expect(links).toHaveCount(6);
  await nav.getByRole('link', { name: 'Alerty' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Alerty' })).toBeVisible();

  // Stránka nemá vodorovný posuvník.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  for (const link of await links.all()) {
    await expect(link).toBeInViewport();
  }
  await nav.getByRole('link', { name: 'Události' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Události' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
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

test('topologie je na mobilu bez vodorovného posuvníku a graf se vykreslí', async ({ page }) => {
  await loginOk(page);
  // Z navigace se otevře 3D se všemi objekty.
  await page.goto('/topology');
  await expect(page.getByTestId('map3d')).toBeVisible();
  await page.waitForFunction(() => (document.getElementById('map3d')?.map3d?.stats().nodes ?? 0) > 0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

  // Okolí objektu ve 2D.
  await page.goto('/topology?mode=neighborhood');
  await expect(page.getByTestId('graph')).toBeVisible();
  await page.waitForFunction(() => (document.getElementById('cy')?.cy?.nodes().length ?? 0) > 0);
  await expect(page.getByTestId('node-panel').getByTestId('node-card')).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('karta serveru s agentem je na mobilu bez vodorovného posuvníku', async ({ page }) => {
  await loginOk(page);
  await page.goto('/inventory?q=e2e-gpu');
  await page.getByRole('link', { name: 'e2e-gpu.example.com' }).click();
  await expect(page.getByTestId('host')).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
