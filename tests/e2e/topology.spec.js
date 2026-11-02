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

test('rozvržení do pater: pásy pater, web nad databází, volba se pamatuje v adrese', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => consoleErrors.push(String(err)));
  await loginOk(page);
  await page.goto(`/topology?center=${idOf(webUrl)}`);
  await page.waitForFunction(() => document.getElementById('cy')?.cy !== undefined);
  const switcher = page.getByTestId('layout-switch');
  await expect(switcher.getByRole('button', { name: 'Automaticky' })).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => document.getElementById('cy').cy.nodes('.band').length)).toBe(0);

  await switcher.getByRole('button', { name: 'Patra' }).click();
  await expect(switcher.getByRole('button', { name: 'Patra' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page).toHaveURL(/layout=floors/);
  const floors = () => page.evaluate(() => {
    const { cy } = document.getElementById('cy');
    const y = (name) => cy.nodes().filter((n) => n.data('name') === name)[0].position('y');
    return {
      bands: cy.nodes('.band').sort((a, b) => a.position('y') - b.position('y')).map((b) => b.data('label')),
      webAboveDb: y('topo-web') < y('topo-db'),
    };
  });
  expect(await floors()).toEqual({ bands: ['Weby a API', 'Služby a databáze'], webAboveDb: true });

  // Klik na uzel funguje i v patrech (pásy klikání nezachytí).
  const card = page.getByTestId('node-panel').getByTestId('node-card');
  const pos = await page.evaluate(() => {
    const { cy } = document.getElementById('cy');
    return cy.nodes().filter((n) => n.data('name') === 'topo-db')[0].renderedPosition();
  });
  const box = await page.getByTestId('graph').boundingBox();
  await page.mouse.click(box.x + pos.x, box.y + pos.y);
  await expect(card).toHaveAttribute('data-node-id', idOf(dbUrl));

  // Volba přežije znovunačtení i odeslání formuláře.
  await page.reload();
  await page.waitForFunction(() => document.getElementById('cy')?.cy !== undefined);
  expect(await floors()).toEqual({ bands: ['Weby a API', 'Služby a databáze'], webAboveDb: true });
  await page.locator('#t-depth').selectOption('1');
  await page.getByRole('button', { name: 'Zobrazit', exact: true }).click();
  await expect(page).toHaveURL(/layout=floors/);

  await page.getByTestId('layout-switch').getByRole('button', { name: 'Automaticky' }).click();
  await expect(page).not.toHaveURL(/layout=floors/);
  expect(await page.evaluate(() => document.getElementById('cy').cy.nodes('.band').length)).toBe(0);
  expect(consoleErrors).toEqual([]);
});

test('3D pohled: patra v prostoru, klik na objekt otevře kartu, seznam vybírá, návrat do 2D', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => consoleErrors.push(String(err)));
  await loginOk(page);
  await page.goto(`/topology?center=${idOf(webUrl)}`);
  await page.waitForFunction(() => document.getElementById('cy')?.cy !== undefined);
  const switcher = page.getByTestId('layout-switch');
  await expect(page.getByRole('button', { name: 'Pohled shora' })).toBeHidden();

  await switcher.getByRole('button', { name: '3D' }).click();
  await expect(page).toHaveURL(/layout=3d/);
  await expect(page.getByTestId('map3d')).toBeVisible();
  await expect(page.getByTestId('graph')).toBeHidden();
  await page.waitForFunction(() => document.getElementById('map3d')?.map3d !== undefined);
  const stats = await page.evaluate(() => document.getElementById('map3d').map3d.stats());
  expect(stats).toMatchObject({ nodes: 2, edges: 1, floors: ['Weby a API', 'Služby a databáze'], selected: idOf(webUrl) });

  // Klik na kouli databáze v prostoru.
  await page.waitForFunction(() => Number(document.getElementById('map3d').dataset.frames ?? 0) > 0);
  const card = page.getByTestId('node-panel').getByTestId('node-card');
  const clickNode = async (id) => {
    const pos = await page.evaluate((nodeId) => document.getElementById('map3d').map3d.screenPosition(nodeId), id);
    const box = await page.getByTestId('map3d').boundingBox();
    await page.mouse.click(box.x + pos.x, box.y + pos.y);
  };
  await clickNode(idOf(dbUrl));
  await expect(card).toHaveAttribute('data-node-id', idOf(dbUrl));
  expect(await page.evaluate(() => document.getElementById('map3d').map3d.stats().selected)).toBe(idOf(dbUrl));

  // Seznam objektů vybírá i ve 3D, ovládací tlačítka fungují.
  await page.getByTestId('node-list').getByRole('button', { name: /topo-web/ }).click();
  await expect(card).toHaveAttribute('data-node-id', idOf(webUrl));
  await page.getByRole('button', { name: 'Pohled shora' }).click();
  await page.getByRole('button', { name: 'Roztáhnout patra' }).click();
  await expect(page.getByRole('button', { name: 'Roztáhnout patra' })).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Celý graf' }).click();

  // Volba přežije znovunačtení.
  await page.reload();
  await page.waitForFunction(() => document.getElementById('map3d')?.map3d !== undefined);
  await expect(page.getByTestId('map3d')).toBeVisible();

  // Zpět do pater.
  await page.getByTestId('layout-switch').getByRole('button', { name: 'Patra' }).click();
  await expect(page.getByTestId('map3d')).toBeHidden();
  await expect(page.getByTestId('graph')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Pohled shora' })).toBeHidden();
  expect(await page.evaluate(() => document.getElementById('cy').cy.nodes('.band').length)).toBe(2);
  expect(consoleErrors).toEqual([]);
});

test('bez WebGL zůstane mapa v patrech s upozorněním', async ({ page }) => {
  await page.addInitScript(() => {
    const original = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function getContext(type, ...rest) {
      return String(type).startsWith('webgl') ? null : original.call(this, type, ...rest);
    };
  });
  await loginOk(page);
  await page.goto(`/topology?center=${idOf(webUrl)}&layout=3d`);
  await expect(page.getByTestId('webgl-missing')).toBeVisible();
  await expect(page).toHaveURL(/layout=floors/);
  await expect(page.getByTestId('map3d')).toBeHidden();
  await page.waitForFunction(() => document.getElementById('cy')?.cy?.nodes('.band').length === 2);
});
