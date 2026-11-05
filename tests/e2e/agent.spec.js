import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { promisify } from 'node:util';
import { createObject, loginOk, runWorker } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const fixtures = resolve(import.meta.dirname, '../fixtures/agent');
let work;
let token;

test.beforeAll(() => {
  work = mkdtempSync(join(tmpdir(), 'nordvarr-e2e-agent-'));
  cpSync(join(fixtures, 'root'), join(work, 'root'), { recursive: true });
});

test.afterAll(() => {
  rmSync(work, { recursive: true, force: true });
});

// Skutečný skript agenta z nainstalovaného zipu nad falešným /proc a /sys (bez proxy, jen na lokální server).
async function agent(baseURL) {
  const env = {
    PATH: `${join(fixtures, 'bin')}:/usr/local/bin:/usr/bin:/bin`,
    NORDVARR_URL: baseURL,
    NORDVARR_TOKEN: token,
    NORDVARR_ROOT: join(work, 'root'),
    NORDVARR_STATE_DIR: join(work, 'state'),
    NORDVARR_HOSTNAME: 'e2e-gpu.example.com',
    NORDVARR_CONF: join(work, 'neni.conf'),
    NORDVARR_SMARTCTL: join(fixtures, 'bin/smartctl'),
    NORDVARR_NVIDIA_SMI: join(fixtures, 'bin/nvidia-smi'),
    NORDVARR_DOCKER: join(fixtures, 'bin/docker'),
    NORDVARR_SYSTEMCTL: join(fixtures, 'bin/systemctl'),
    // Jen běžící služba, aby důvodem stavu zůstal selhávající disk.
    NORDVARR_SERVICES: 'httpd php-fpm',
    LC_ALL: 'C',
  };
  const script = join(process.env.E2E_SITE, 'nordvarr/agent/nordvarr-agent.sh');
  const { stdout } = await promisify(execFile)('bash', [script, '--test'], { env });
  return stdout;
}

test('agent na serveru: hlášení, karta s metrikami a SMART, kontrola a alert', async ({ page, baseURL }) => {
  await loginOk(page);
  await page.goto('/settings');
  await page.getByLabel('Název').fill('Agent e2e');
  await page.getByLabel('Zdroj dat').fill('agent');
  for (const scope of ['inventory:write', 'events:write']) {
    await page.locator(`input[name="scopes[]"][value="${scope}"]`).uncheck();
  }
  await page.locator('input[name="scopes[]"][value="agent:write"]').check();
  await page.getByRole('button', { name: 'Vytvořit token' }).click();
  token = (await page.getByTestId('new-token').textContent()).trim();

  // Tři hlášení (práh kontroly agent), mezi nimi o minutu víc práce CPU.
  expect(await agent(baseURL)).toContain('OK: {"object_id"');
  const stat = join(work, 'root/proc/stat');
  writeFileSync(stat, 'cpu  10600 0 5300 80900 1000 0 0 0 0 0\n');
  writeFileSync(join(work, 'root/proc/uptime'), '864060.50 1700100.00\n');
  await agent(baseURL);
  writeFileSync(stat, 'cpu  11200 0 5600 81800 1000 0 0 0 0 0\n');
  writeFileSync(join(work, 'root/proc/uptime'), '864120.50 1700200.00\n');
  await agent(baseURL);
  expect(readFileSync(join(work, 'state/payload.json'), 'utf8')).not.toContain(token);

  await page.goto('/inventory?q=e2e-gpu');
  await page.getByRole('link', { name: 'e2e-gpu.example.com' }).click();
  const host = page.getByTestId('host');
  await expect(host).toBeVisible();
  await expect(host).toContainText('Debian GNU/Linux 13 (trixie)');
  await expect(host.getByTestId('host-gauges')).toContainText('CPU50 %');
  await expect(host.getByTestId('host-gauges')).toContainText('GPU 087 %');
  await expect(host.getByTestId('host-disks')).toContainText('/mnt/zalohy disk');
  await expect(host.getByTestId('host-smart')).toContainText('EXAMPLE HDD 4TB');
  await expect(host.getByTestId('host-smart')).toContainText('selhává');
  await expect(host.getByTestId('host-smart')).toContainText('opotřebení', { ignoreCase: true });
  // Kontejnery Dockeru: tabulka na kartě a objekty typu kontejner pod serverem.
  await expect(host.getByTestId('host-containers')).toContainText('Restarting (1) 5 seconds ago');
  await expect(host.getByTestId('host-services')).toContainText('php-fpm');
  await expect(host.getByTestId('host-logs')).toContainText('/var/log/httpd/error_log');
  await expect(page.getByTestId('relationships')).toContainText('queue-worker');
  await expect(page.getByTestId('diagnosis').getByTestId('object-status')).toContainText('nefunguje');
  await expect(page.getByTestId('diagnosis').getByTestId('object-status')).toContainText('disk_failing');

  // Worker vyhodnotí alerty.
  await runWorker();
  await page.goto('/alerts');
  // Alert serveru (disk_failing); kontejnery mají vlastní alerty, ve kterých je server uvedený jako příčina.
  await expect(page.getByTestId('alerts').getByRole('row').filter({ hasText: 'e2e-gpu.example.com' }).filter({ hasText: 'disk_failing' })).toHaveCount(1);

  // Kontejner má vlastní kontrolu od agenta.
  await page.goto('/inventory?q=queue-worker');
  await page.getByRole('link', { name: 'queue-worker' }).click();
  await expect(page.getByTestId('checks')).toContainText('Kontejner (agent)');
  await expect(page.getByTestId('checks')).toContainText('container_down');

  // Panel v topologii.
  await page.goto('/inventory?q=e2e-gpu');
  await page.getByRole('link', { name: 'e2e-gpu.example.com' }).click();
  await page.getByTestId('show-topology').click();
  await expect(page.getByTestId('node-panel').getByTestId('node-host')).toContainText('GPU 0');

  // Ve 2D má uzel serveru pruhy prostředků (obrázek SVG) a v popisku jen název.
  const node2d = await page.evaluate(() => {
    const n = document.getElementById('cy').cy.nodes().filter((x) => x.data('name') === 'e2e-gpu.example.com')[0];
    return { hasRes: n.data('hasRes'), label: n.data('label'), img: n.data('resImg') ?? '', bg: n.style('background-image') };
  });
  expect(node2d.hasRes).toBe(true);
  expect(node2d.label).toBe('e2e-gpu.example.com');
  expect(node2d.img).toMatch(/^data:image\/svg\+xml/);
  expect(decodeURIComponent(node2d.img)).toContain('>Disk</text>');
  expect(node2d.bg).toContain('data:image/svg+xml');

  // Ve 3D má server pod názvem vytížení z posledního hlášení.
  await page.getByTestId('layout-switch').getByRole('button', { name: '3D' }).click();
  await page.waitForFunction(() => document.getElementById('map3d')?.map3d !== undefined);
  const label = page.locator('.map3d-label').filter({ hasText: 'e2e-gpu.example.com' });
  await expect(label.locator('.map3d-metrics')).toHaveText(/^CPU \d+ %, RAM \d+ %, Disk \d+ %, GPU \d+ %$/);
  // Disk se zálohami má zaplněné inody (99 %), proto je červeně.
  await expect(label.locator('[data-res="disk"]')).toHaveClass(/res-crit/);
  await expect(label.locator('[data-res="disk"]')).toHaveAttribute('title', '/mnt/zalohy disk (inody)');
  await expect(label.locator('[data-res="gpu"]')).toHaveClass(/res-none/);

  // Servery jako kostky: server s agentem je kostka, ostatní objekty zůstanou koule; volba se pamatuje.
  const serverId = await page.evaluate(() => document.getElementById('cy').cy.nodes().filter((x) => x.data('name') === 'e2e-gpu.example.com')[0].id());
  const cubes = () => page.evaluate(() => document.getElementById('map3d').map3d.stats().cubes);
  expect(await cubes()).toEqual([]);
  await page.getByTestId('cubes-toggle').click();
  await expect(page.getByTestId('cubes-toggle')).toHaveAttribute('aria-pressed', 'true');
  expect(await cubes()).toEqual([serverId]);
  // Klik na stěnu kostky natočí kameru kolmo na ni (boční stěna: vodorovný pohled) a přiblíží.
  const pos = await page.evaluate((id) => document.getElementById('map3d').map3d.screenPosition(id), serverId);
  const box = await page.getByTestId('map3d').boundingBox();
  await page.mouse.click(box.x + pos.x, box.y + pos.y);
  await expect.poll(() => page.evaluate(() => document.getElementById('map3d').map3d.stats().lastFace?.normal[1])).toBe(0);
  await expect.poll(() => page.evaluate(() => Math.abs(document.getElementById('map3d').map3d.stats().view.polar - Math.PI / 2) < 0.01)).toBe(true);
  expect(await page.evaluate(() => document.getElementById('map3d').map3d.stats().lastFace.id)).toBe(serverId);
  await page.reload();
  await page.waitForFunction(() => document.getElementById('map3d')?.map3d !== undefined);
  expect(await cubes()).toEqual([serverId]);
  await expect(page.getByTestId('cubes-toggle')).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('cubes-toggle').click();
  expect(await cubes()).toEqual([]);
});

test('logy webu: cesty na kartě webu, agent serveru je pošle, karta webu je ukáže', async ({ page, baseURL }) => {
  await loginOk(page);
  mkdirSync(join(work, 'root/var/www/vhosts/shop_example_com'), { recursive: true });
  writeFileSync(join(work, 'root/var/www/vhosts/shop_example_com/access.log'), '203.0.113.9 - - "GET /kosik HTTP/1.1" 200\n');
  const webUrl = await createObject(page, 'logy-shop.example.com', 'website');

  // Bez cest je formulář rozbalený.
  await page.locator('#log-paths').fill('/var/www/vhosts/shop_example_com/access.log');
  await page.getByRole('button', { name: 'Uložit cesty' }).click();
  await expect(page.getByTestId('logs-no-agent')).toBeVisible();

  // Web závisí na serveru s agentem.
  await page.locator('#rel-type').selectOption('out:depends_on');
  await page.locator('#rel-target').selectOption({ label: 'e2e-gpu.example.com' });
  await page.getByRole('button', { name: 'Přidat vztah' }).click();
  await expect(page.getByTestId('log-waiting')).toBeVisible();

  // První hlášení přinese cesty, druhé pošle logy.
  await agent(baseURL);
  expect(readFileSync(join(work, 'state/log-paths'), 'utf8')).toBe('/var/www/vhosts/shop_example_com/access.log\n');
  await agent(baseURL);
  await page.goto(webUrl);
  await expect(page.getByTestId('object-logs')).toContainText('GET /kosik HTTP/1.1');
  await expect(page.getByTestId('object-logs')).toContainText('e2e-gpu.example.com');
});

test('události ze serveru: agent event a site, přehled Události s důležitostí', async ({ page, baseURL }) => {
  await loginOk(page);
  const env = {
    PATH: `${join(fixtures, 'bin')}:/usr/local/bin:/usr/bin:/bin`,
    NORDVARR_URL: baseURL,
    NORDVARR_TOKEN: token,
    NORDVARR_ROOT: join(work, 'root'),
    NORDVARR_STATE_DIR: join(work, 'state'),
    NORDVARR_HOSTNAME: 'e2e-gpu.example.com',
    NORDVARR_CONF: join(work, 'neni.conf'),
    LC_ALL: 'C',
  };
  const script = join(process.env.E2E_SITE, 'nordvarr/agent/nordvarr-agent.sh');
  const run = async (...args) => (await promisify(execFile)('bash', [script, ...args], { env })).stdout;

  expect(await run('site', 'udalosti-shop.example.com', '/var/www/vhosts/udalosti/access.log')).toContain('"created":true');
  expect(await run('event', '-s', 'critical', '-t', 'security', 'CrowdSec zablokoval 25 adres')).toContain('OK:');
  expect(await run('event', '-s', 'notice', '-t', 'business', '-w', 'udalosti-shop.example.com', 'Nová objednávka č. 7', 'zákazník: Example s.r.o.')).toContain('OK:');
  expect(await run('event', 'AI agent: obnovil jsem certifikát')).toContain('OK:');

  await page.getByRole('navigation', { name: 'Hlavní navigace' }).getByRole('link', { name: 'Události' }).click();
  const feed = page.getByTestId('event-feed');
  await expect(feed.locator('[data-severity="critical"]')).toContainText('CrowdSec zablokoval 25 adres');
  await expect(feed.locator('[data-severity="notice"]')).toContainText('udalosti-shop.example.com');
  await expect(feed.locator('[data-severity="info"]').filter({ hasText: 'AI agent' })).toContainText('vzkaz');
  await page.getByLabel('Nejméně').selectOption('warning');
  await page.getByRole('button', { name: 'Zobrazit' }).click();
  await expect(feed.locator('li')).toHaveCount(1);

  // Web ze serveru má cesty k logům a událost na kartě.
  await page.goto('/inventory?q=udalosti-shop');
  await page.getByRole('link', { name: 'udalosti-shop.example.com' }).click();
  await expect(page.locator('#log-paths')).toHaveValue('/var/www/vhosts/udalosti/access.log');
  await expect(page.getByTestId('timeline')).toContainText('Nová objednávka č. 7');
});

test('zvukové upozornění: zvuk podle důležitosti, ztlumení na hodinu, volba zvuku', async ({ page, baseURL }) => {
  await loginOk(page);
  const env = {
    PATH: `${join(fixtures, 'bin')}:/usr/local/bin:/usr/bin:/bin`,
    NORDVARR_URL: baseURL,
    NORDVARR_TOKEN: token,
    NORDVARR_ROOT: join(work, 'root'),
    NORDVARR_STATE_DIR: join(work, 'state'),
    NORDVARR_HOSTNAME: 'e2e-gpu.example.com',
    NORDVARR_CONF: join(work, 'neni.conf'),
    LC_ALL: 'C',
  };
  const script = join(process.env.E2E_SITE, 'nordvarr/agent/nordvarr-agent.sh');
  const event = async (...args) => promisify(execFile)('bash', [script, 'event', ...args], { env });
  const menu = page.getByTestId('sound-menu');
  await page.goto('/events');
  await page.evaluate(() => window.localStorage.removeItem('nordvarr.sound.since'));
  await page.reload();
  await expect(menu).toHaveAttribute('data-polled', '1');
  const poll = () => page.evaluate(() => window.nordvarrSound.poll());
  const played = () => menu.getAttribute('data-played');

  // Kritická událost: červený zvuk (výchozí siréna) a upozornění na obrazovce.
  await page.waitForTimeout(1100);
  await event('-s', 'critical', '-t', 'security', 'Zvuk: útok na přihlášení');
  await poll();
  await expect(menu).toHaveAttribute('data-last-played', 'critical:siren');
  await expect(page.locator('[data-sound-toast]')).toContainText('Zvuk: útok na přihlášení');
  await expect(page.locator('[data-sound-toast]')).toHaveClass(/sound-toast-critical/);

  // Ztlumení na hodinu: upozornění se ukáže, zvuk ne.
  await menu.locator('summary').click();
  await page.getByTestId('sound-mute-hour').click();
  await expect(menu).toHaveAttribute('data-muted', 'until');
  await expect(menu.locator('[data-sound-state]')).toContainText('Ztlumeno do');
  const before = await played();
  await page.waitForTimeout(1100);
  await event('-s', 'warning', 'Zvuk: varování během ztlumení');
  await poll();
  await expect(page.locator('[data-sound-toast]')).toContainText('Zvuk: varování během ztlumení');
  expect(await played()).toBe(before);

  // Po hodině se ztlumení zruší samo.
  await page.evaluate(() => window.localStorage.setItem('nordvarr.sound.muted', String(Date.now() - 1000)));
  await page.reload();
  await expect(menu).toHaveAttribute('data-muted', 'no');

  // Volba zvuku pro oranžovou úroveň se uloží a přehraje.
  await menu.locator('summary').click();
  await page.locator('#sound-warning').selectOption('gong');
  await expect(menu).toHaveAttribute('data-last-played', 'warning:gong');
  await page.waitForTimeout(1100);
  await event('-s', 'warning', 'Zvuk: varování po ztlumení');
  await poll();
  await expect(menu).toHaveAttribute('data-last-played', 'warning:gong');
});

test('skript agenta jde stáhnout bez přihlášení', async ({ request }) => {
  const response = await request.get('/agent/nordvarr-agent.sh');
  expect(response.status()).toBe(200);
  expect(await response.text()).toContain('Nordvarr: hostový agent pro Linux');
});
