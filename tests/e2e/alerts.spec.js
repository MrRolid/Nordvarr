import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { createServer } from 'node:http';
import { addTcpCheck, createObject, loginOk, runWorker } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const SECRET = 'e2e-tajemstvi-webhooku';
const received = [];
let receiver;
let receiverUrl;

// Lokální přijímač webhooků (žádná skutečná služba).
test.beforeAll(async () => {
  receiver = createServer((req, res) => {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      received.push({ headers: req.headers, body, json: JSON.parse(body) });
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{}');
    });
  });
  await new Promise((resolve) => receiver.listen(0, '127.0.0.1', resolve));
  receiverUrl = `http://127.0.0.1:${receiver.address().port}/hook`;
});

test.afterAll(async () => {
  await new Promise((resolve) => receiver.close(resolve));
});

test('webhook kanál s testovacím odesláním a podpisem', async ({ page }) => {
  await loginOk(page);
  await page.goto('/settings');
  await page.getByTestId('add-channel').click();
  await page.getByRole('link', { name: 'Webhook' }).click();
  await page.locator('#ch-name').fill('E2E webhook');
  await page.locator('#ch-url').fill(receiverUrl);
  await page.locator('#ch-secret').fill(SECRET);
  await page.getByRole('button', { name: 'Uložit' }).click();
  await expect(page.getByTestId('flash')).toContainText('Kanál byl vytvořen');

  await page.getByTestId('send-test').click();
  await expect(page.getByTestId('flash')).toContainText('Testovací zpráva byla odeslána');
  expect(received).toHaveLength(1);
  const expected = 'sha256=' + createHmac('sha256', SECRET).update(received[0].body).digest('hex');
  expect(received[0].headers['x-nordvarr-signature']).toBe(expected);
  expect(received[0].json.event).toBe('test');
  await expect(page.getByTestId('deliveries')).toContainText('odesláno');
});

test('výpadek databáze: web je potlačený a má pravděpodobnou příčinu', async ({ page }) => {
  await loginOk(page);
  const dbUrl = await createObject(page, 'e2e-db', 'database');
  const webUrl = await createObject(page, 'e2e-web', 'website');

  await page.goto(webUrl);
  await page.locator('#rel-type').selectOption('out:depends_on');
  await page.locator('#rel-target').selectOption({ label: 'e2e-db' });
  await page.getByRole('button', { name: 'Přidat vztah' }).click();
  await expect(page.getByTestId('relationships')).toContainText('závisí na e2e-db');

  await addTcpCheck(page, webUrl);
  await addTcpCheck(page, dbUrl);
  received.length = 0;
  await runWorker();

  const names = received.filter((r) => r.json.event === 'alert.opened').map((r) => r.json.object.name);
  expect(names).toContain('e2e-db');
  expect(names).not.toContain('e2e-web');

  await page.goto('/alerts');
  const webRow = page.getByTestId('alerts').getByRole('row').filter({ hasText: 'e2e-web' });
  await expect(webRow.getByTestId('probable-cause')).toContainText('e2e-db');
  await expect(webRow.getByTestId('suppressed')).toBeVisible();

  await page.goto(webUrl);
  await expect(page.getByTestId('probable-cause')).toContainText('e2e-db');

  // Druhý běh nic dalšího nepošle.
  received.length = 0;
  await runWorker();
  expect(received.filter((r) => r.json.object?.name?.startsWith('e2e-'))).toHaveLength(0);
});

test('údržbové okno', async ({ page }) => {
  await loginOk(page);
  await page.goto('/alerts');
  await page.getByLabel('Objekty s tagem').check();
  await page.locator('#mw-tag').fill('e2e-udrzba');
  await page.getByLabel(/Pozastavit kontroly/).check();
  await page.locator('#mw-note').fill('e2e okno');
  await page.getByRole('button', { name: 'Naplánovat okno' }).click();
  await expect(page.getByTestId('flash')).toContainText('naplánováno');
  const row = page.getByTestId('windows').getByRole('row').filter({ hasText: 'e2e okno' });
  await expect(row.getByTestId('window-state')).toHaveText('probíhá');
  await row.getByRole('button', { name: 'Ukončit teď' }).click();
  await expect(page.getByTestId('windows').getByRole('row').filter({ hasText: 'e2e okno' }).getByTestId('window-state')).toHaveText('skončilo');
});
