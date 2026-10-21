import { test, expect } from '@playwright/test';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loginOk } from './helpers.js';

// Testy v souboru na sebe navazují (token, objekty z API, export).
test.describe.configure({ mode: 'serial' });

let token = '';

test('vytvoření API tokenu v nastavení (zobrazí se jen jednou)', async ({ page }) => {
  await loginOk(page);
  await page.goto('/settings');
  await page.getByLabel('Název').fill('Ansible e2e');
  await page.getByLabel('Zdroj dat').fill('ansible');
  await page.getByRole('button', { name: 'Vytvořit token' }).click();
  token = (await page.getByTestId('new-token').textContent()).trim();
  expect(token).toMatch(/^nv_[0-9a-f]{12}_[0-9a-f]{48}$/);

  await page.goto('/settings');
  await expect(page.getByTestId('tokens')).toContainText('ansible');
  expect(await page.content()).not.toContain(token);
});

test('opakovaný upsert nic nezduplikuje a událost je na ose objektu i souseda', async ({ page, request }) => {
  const auth = { Authorization: `Bearer ${token}` };
  const app1 = { external_id: 'vm:app1', type: 'vm', name: 'app1.example.com', attributes: { ip: '192.0.2.33' } };
  const shop = {
    external_id: 'web:shop.example.com', type: 'website', name: 'shop.example.com',
    attributes: { document_root: '/srv/www/shop' }, tags: ['eshop'],
    relationships: [{ kind: 'depends_on', to_external_id: 'vm:app1' }],
    docs_markdown: '# E-shop\n\nVolitelný **text** dokumentace',
  };

  // Odkaz na neexistující objekt -> 422 se seznamem.
  const missing = await request.post('/api/v1/objects/upsert', { headers: auth, data: shop });
  expect(missing.status()).toBe(422);
  expect((await missing.json()).missing_external_ids).toEqual(['vm:app1']);

  expect((await request.post('/api/v1/objects/upsert', { headers: auth, data: app1 })).status()).toBe(201);
  const created = await request.post('/api/v1/objects/upsert', { headers: auth, data: shop });
  expect(created.status()).toBe(201);
  const shopId = (await created.json()).id;

  for (let i = 0; i < 3; i++) {
    const again = await request.post('/api/v1/objects/upsert', { headers: auth, data: shop });
    expect(again.status()).toBe(200);
    const body = await again.json();
    expect(body.id).toBe(shopId);
    expect(body.changed).toBe(false);
  }

  const event = {
    event_id: 'deploy-2026-10-20-1', type: 'deploy', occurred_at: '2026-10-20T13:58:00Z',
    summary: 'release 42', objects: [{ external_id: 'vm:app1' }],
  };
  expect((await request.post('/api/v1/events', { headers: auth, data: event })).status()).toBe(201);
  expect((await request.post('/api/v1/events', { headers: auth, data: event })).status()).toBe(200);

  // Bez tokenu API odmítá.
  expect((await request.post('/api/v1/events', { data: event })).status()).toBe(401);

  await loginOk(page);
  await page.goto('/inventory');
  await page.getByLabel('Hledat').fill('example.com');
  await page.getByRole('button', { name: 'Filtrovat' }).click();
  await expect(page.getByTestId('inventory-count')).toHaveText('Nalezeno objektů: 2');

  await page.getByRole('link', { name: 'shop.example.com', exact: true }).click();
  await expect(page.getByTestId('object-name')).toHaveText('shop.example.com');
  await expect(page.getByTestId('docs').locator('strong')).toHaveText('text');
  await expect(page.getByTestId('timeline')).toContainText('release 42');
  await expect(page.getByTestId('neighbor-event')).toContainText('app1.example.com');
  await expect(page.getByTestId('relationships')).toContainText('závisí na app1.example.com');

  await page.getByTestId('relationships').getByRole('link', { name: 'app1.example.com' }).click();
  await expect(page.getByTestId('timeline')).toContainText('release 42');
  await expect(page.getByTestId('neighbor-event')).toHaveCount(0);
});

test('ruční úpravy: atribut s rozporem, dokumentace s revizí, tag', async ({ page }) => {
  await loginOk(page);
  await page.goto('/inventory?q=app1');
  await page.getByRole('link', { name: 'app1.example.com' }).click();

  await page.getByLabel('Klíč').fill('ip');
  await page.getByLabel('Hodnota').fill('192.0.2.99');
  await page.getByRole('button', { name: 'Uložit atribut' }).click();
  await expect(page.getByTestId('conflict')).toBeVisible();
  await expect(page.getByTestId('attributes')).toContainText('platí');

  await page.getByTestId('edit-docs').click();
  await page.getByLabel('Text (Markdown)').fill('## Postup\n\n1. první krok\n2. druhý krok\n\n<script>alert(1)</script>');
  await page.getByRole('button', { name: 'Uložit' }).click();
  await expect(page.getByTestId('docs').locator('ol li')).toHaveCount(2);
  await expect(page.getByTestId('docs')).toContainText('<script>alert(1)</script>');

  await page.getByPlaceholder('nový tag').fill('aplikace');
  await page.getByRole('button', { name: 'Přidat tag' }).click();
  await expect(page.getByTestId('object-tags')).toContainText('aplikace');
});

test('export a import inventáře dá stejný výsledek', async ({ page }) => {
  await loginOk(page);
  await page.goto('/settings');
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export').click()]);
  const dir = mkdtempSync(join(tmpdir(), 'nordvarr-e2e-export-'));
  try {
    const file = join(dir, 'export.json');
    await download.saveAs(file);
    const first = JSON.parse(readFileSync(file, 'utf8'));
    expect(first.objects.length).toBe(2);

    await page.getByLabel('Soubor s exportem').setInputFiles(file);
    await page.getByLabel(/import nahradí celý současný inventář/).check();
    await page.getByRole('button', { name: 'Importovat' }).click();
    await expect(page.getByTestId('flash')).toContainText('Import hotov: objektů 2');

    const [download2] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export').click()]);
    const file2 = join(dir, 'export2.json');
    await download2.saveAs(file2);
    const second = JSON.parse(readFileSync(file2, 'utf8'));
    delete first.exported_at;
    delete second.exported_at;
    expect(second).toEqual(first);

    // Neplatný soubor inventář nezmění.
    const bad = join(dir, 'spatny.json');
    writeFileSync(bad, '{"format":"neco-jineho"}');
    await page.getByLabel('Soubor s exportem').setInputFiles(bad);
    await page.getByLabel(/import nahradí celý současný inventář/).check();
    await page.getByRole('button', { name: 'Importovat' }).click();
    await expect(page.getByTestId('flash')).toContainText('není export inventáře');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
  await page.goto('/inventory');
  await expect(page.getByTestId('inventory-count')).toHaveText('Nalezeno objektů: 2');
});
