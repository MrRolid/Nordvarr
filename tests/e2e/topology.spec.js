import { test, expect } from '@playwright/test';
import { createServer } from 'node:http';
import { addTcpCheck, createObject, loginOk, runWorker } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const received = [];
let receiver;
let receiverUrl;
let dbUrl;
let webUrl;
const idOf = (url) => url.split('/objects/')[1];

// Lokální přijímač webhooků (žádná skutečná služba).
test.beforeAll(async () => {
  receiver = createServer((req, res) => {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      received.push(JSON.parse(body));
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

test('spadne databáze -> web je unhealthy, notifikace webu je potlačena, karta webu ukazuje příčinu a poslední deploy v okolí', async ({ page }) => {
  await loginOk(page);
  await page.goto('/settings');
  await page.getByTestId('add-channel').click();
  await page.getByRole('link', { name: 'Webhook' }).click();
  await page.locator('#ch-name').fill('Topologie e2e');
  await page.locator('#ch-url').fill(receiverUrl);
  await page.locator('#ch-secret').fill('e2e-tajemstvi-topologie');
  await page.getByRole('button', { name: 'Uložit' }).click();
  await expect(page.getByTestId('flash')).toContainText('Kanál byl vytvořen');

  dbUrl = await createObject(page, 'topo-db', 'database');
  webUrl = await createObject(page, 'topo-web', 'website');
  await page.goto(webUrl);
  await page.locator('#rel-type').selectOption('out:depends_on');
  await page.locator('#rel-target').selectOption({ label: 'topo-db' });
  await page.getByRole('button', { name: 'Přidat vztah' }).click();
  await expect(page.getByTestId('relationships')).toContainText('závisí na topo-db');

  // Deploy na databázi, tedy u souseda webu.
  await page.goto(dbUrl);
  await page.getByText('Přidat událost').click();
  await page.locator('#ev-type').selectOption('deploy');
  await page.locator('#ev-summary').fill('topo-db migrace 7');
  await page.getByRole('button', { name: 'Uložit událost' }).click();

  await addTcpCheck(page, webUrl);
  await addTcpCheck(page, dbUrl);
  received.length = 0;
  await runWorker();

  // Notifikace odešla jen za databázi.
  const opened = received.filter((r) => r.event === 'alert.opened').map((r) => r.object.name);
  expect(opened).toContain('topo-db');
  expect(opened).not.toContain('topo-web');

  // Diagnostická hlavička karty webu.
  await page.goto(webUrl);
  const diag = page.getByTestId('diagnosis');
  await expect(diag.getByTestId('object-status')).toContainText('nefunguje');
  await expect(diag.getByTestId('object-status')).toContainText('TCP: tcp_refused');
  await expect(diag.getByTestId('probable-cause')).toContainText('topo-db');
  await expect(diag.getByTestId('last-change')).toContainText('nasazení');
  await expect(diag.getByTestId('last-change')).toContainText('topo-db migrace 7');
  await expect(diag.getByTestId('last-change')).toContainText('u souseda: topo-db');
  await expect(diag.getByTestId('dependencies')).toContainText('závisí na topo-db');
  await expect(diag.getByTestId('dependencies')).toContainText('nefunguje');
  await expect(diag.getByTestId('alerts-90')).toHaveText('1');
  await expect(page.getByTestId('incidents')).toContainText('tcp_refused');
});

test('mapa okolí: graf, příčinná hrana a karta uzlu po kliknutí', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => consoleErrors.push(String(err)));
  await loginOk(page);
  await page.goto(webUrl);
  await page.getByTestId('show-topology').click();
  await expect(page).toHaveURL(new RegExp(`center=${idOf(webUrl)}`));

  await expect(page.getByTestId('graph')).toBeVisible();
  await page.waitForFunction(() => document.getElementById('cy')?.cy !== undefined);
  const graph = await page.evaluate(() => {
    const { cy } = document.getElementById('cy');
    return {
      nodes: cy.nodes().map((n) => n.data('name')).sort(),
      causal: cy.edges('[?causal]').map((e) => [e.source().data('name'), e.target().data('name')]),
      status: Object.fromEntries(cy.nodes().map((n) => [n.data('name'), n.data('status')])),
    };
  });
  expect(graph.nodes).toEqual(['topo-db', 'topo-web']);
  expect(graph.causal).toEqual([['topo-web', 'topo-db']]);
  expect(graph.status).toEqual({ 'topo-db': 'unhealthy', 'topo-web': 'unhealthy' });

  // Centrum je vybrané hned, panel ukazuje jeho kartu.
  const card = page.getByTestId('node-panel').getByTestId('node-card');
  await expect(card).toHaveAttribute('data-node-id', idOf(webUrl));
  await expect(card.getByTestId('probable-cause')).toContainText('topo-db');

  // Klik na uzel databáze přímo v grafu.
  const pos = await page.evaluate(() => {
    const { cy } = document.getElementById('cy');
    return cy.nodes().filter((n) => n.data('name') === 'topo-db')[0].renderedPosition();
  });
  const box = await page.getByTestId('graph').boundingBox();
  await page.mouse.click(box.x + pos.x, box.y + pos.y);
  await expect(card).toHaveAttribute('data-node-id', idOf(dbUrl));
  await expect(card).toContainText('topo-db migrace 7');
  await expect(card).toContainText('Incidenty za 90 dní (1)');

  // Seznam uzlů vede na stejné karty.
  await page.getByTestId('node-list').getByRole('button', { name: /topo-web/ }).click();
  await expect(card).toHaveAttribute('data-node-id', idOf(webUrl));
  await card.getByTestId('open-card').click();
  await expect(page.getByTestId('object-name')).toHaveText('topo-web');

  expect(consoleErrors).toEqual([]);
});

test('filtrový režim podle stavu', async ({ page }) => {
  await loginOk(page);
  await page.goto('/topology');
  await page.getByLabel('Podle filtru').check();
  await page.locator('#t-status').selectOption('unhealthy');
  await page.getByRole('button', { name: 'Zobrazit', exact: true }).click();
  await expect(page).toHaveURL(/mode=filter/);
  const list = page.getByTestId('node-list');
  await expect(list).toContainText('topo-db');
  await expect(list).toContainText('topo-web');
  await page.waitForFunction(() => document.getElementById('cy')?.cy !== undefined);
  const statuses = await page.evaluate(() => [...new Set(document.getElementById('cy').cy.nodes().map((n) => n.data('status')))]);
  expect(statuses).toEqual(['unhealthy']);
});

test('data grafu a diagnózy se obnovují přes ETag', async ({ page }) => {
  await loginOk(page);
  for (const path of [`/topology/data?center=${idOf(webUrl)}`, `/objects/${idOf(webUrl)}/diagnosis`]) {
    const first = await page.request.get(path);
    expect(first.status(), path).toBe(200);
    const etag = first.headers().etag;
    expect(etag, path).toBeTruthy();
    const again = await page.request.get(path, { headers: { 'If-None-Match': etag } });
    expect(again.status(), path).toBe(304);
  }
});

test('mapa se v prohlížeči sama obnovuje', async ({ page }) => {
  await page.clock.install();
  await loginOk(page);
  await page.goto(`/topology?center=${idOf(webUrl)}`);
  await page.waitForFunction(() => document.getElementById('cy')?.cy !== undefined);
  expect(await page.evaluate(() => document.getElementById('cy').dataset.refreshedAt)).toBeUndefined();
  // Aktivní karta se obnovuje každých 15 s.
  await page.clock.fastForward(16000);
  await page.waitForFunction(() => document.getElementById('cy').dataset.refreshedAt !== undefined);
  expect(await page.evaluate(() => document.getElementById('cy').cy.nodes().length)).toBe(2);
});
