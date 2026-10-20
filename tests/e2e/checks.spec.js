import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import { join } from 'node:path';
import { loginOk } from './helpers.js';

test.describe.configure({ mode: 'serial' });

// Spuštění workeru stejně jako z cronu.
function runWorker() {
  const php = process.env.PHP_BINARY || 'php';
  execFileSync(php, [join(process.env.E2E_SITE, 'nordvarr/bin/nordvarr.php'), 'worker'], { stdio: 'inherit' });
}

async function closedPort() {
  return new Promise((resolve) => {
    const server = createServer();
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
  });
}

async function addCheck(page, type, fill) {
  await page.getByTestId('add-check').click();
  await page.getByLabel('Typ').selectOption(type);
  await fill();
  await page.getByRole('button', { name: 'Uložit' }).click();
  await expect(page.getByTestId('flash')).toContainText('Kontrola byla vytvořena');
}

test('kontroly, worker, stav objektu, přehled a heartbeat', async ({ page, baseURL, request }) => {
  await loginOk(page);

  // Objekt s kontrolami.
  await page.goto('/objects/new');
  await page.getByLabel('Typ').selectOption('website');
  await page.getByLabel('Název').fill('monitorovany-web');
  await page.getByRole('button', { name: 'Uložit' }).click();
  await expect(page.getByTestId('object-status')).toContainText('bez kontrol');
  const objectUrl = page.url();

  await addCheck(page, 'http', async () => {
    await page.getByLabel('URL (http nebo https)').fill(`${baseURL}/login`);
    await page.getByLabel('Odpověď musí obsahovat text').fill('Nordvarr');
  });
  await page.goto(objectUrl);
  const port = await closedPort();
  await addCheck(page, 'tcp', async () => {
    await page.locator('#cfg-tcp-host').fill('127.0.0.1');
    await page.locator('#cfg-tcp-port').fill(String(port));
    await page.getByLabel('Počet selhání před změnou stavu').fill('1');
  });
  await page.goto(objectUrl);
  await addCheck(page, 'heartbeat', async () => {
    await page.getByLabel('Tolerance (s)').fill('0');
  });
  const heartbeatUrl = (await page.getByTestId('heartbeat-url').textContent()).trim();
  expect(heartbeatUrl).toMatch(/\/hb\/[0-9a-f]{32}$/);

  runWorker();

  // Stav objektu je nejhorší stav jeho kontrol.
  await page.goto(objectUrl);
  await expect(page.getByTestId('object-status').locator('.status')).toHaveText('nefunguje');
  await expect(page.getByTestId('object-status')).toContainText('TCP: tcp_refused');
  await expect(page.getByTestId('checks')).toContainText('v pořádku');

  // Přehled ukazuje, co nefunguje, a varování o workeru zmizelo.
  await page.goto('/');
  await expect(page.getByTestId('down-now')).toContainText('monitorovany-web');
  await expect(page.getByTestId('down-now')).toContainText('tcp_refused');
  await expect(page.getByTestId('worker-warning')).toHaveCount(0);
  await expect(page.getByTestId('summary-unhealthy')).toHaveText('1');

  // JSON pro obnovování přehledu podporuje ETag.
  const first = await page.request.get('/overview/data');
  expect(first.status()).toBe(200);
  const etag = first.headers()['etag'];
  expect(etag).toBeTruthy();
  const again = await page.request.get('/overview/data', { headers: { 'If-None-Match': etag } });
  expect(again.status()).toBe(304);

  // Heartbeat bez přihlášení.
  const hb = await request.post(heartbeatUrl.replace(/^https?:\/\/[^/]+/, ''));
  expect(hb.status()).toBe(200);
  expect(await hb.text()).toBe('OK\n');
  expect((await request.get('/hb/' + '0'.repeat(32))).status()).toBe(404);

  await page.goto(objectUrl);
  await page.getByTestId('checks').getByRole('link', { name: 'Heartbeat' }).click();
  await expect(page.getByTestId('check-status').locator('.status')).toHaveText('v pořádku');
  await expect(page.getByTestId('results')).toContainText('signál přijat');
});

test('přehled se sám obnoví bez načtení stránky', async ({ page }) => {
  await loginOk(page);
  await page.goto('/');
  const overview = page.locator('#overview');
  await expect(overview).toBeVisible();
  // První obnovení proběhne do 15 s (aktivní karta).
  await expect(overview).toHaveAttribute('data-refreshed-at', /\d+/, { timeout: 20000 });
});
