import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { promisify } from 'node:util';
import { loginOk, runWorker } from './helpers.js';

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
  await expect(page.getByTestId('diagnosis').getByTestId('object-status')).toContainText('nefunguje');
  await expect(page.getByTestId('diagnosis').getByTestId('object-status')).toContainText('disk_failing');

  // Worker vyhodnotí alerty.
  await runWorker();
  await page.goto('/alerts');
  await expect(page.getByTestId('alerts').getByRole('row').filter({ hasText: 'e2e-gpu.example.com' })).toContainText('disk_failing');

  // Panel v topologii.
  await page.goto('/inventory?q=e2e-gpu');
  await page.getByRole('link', { name: 'e2e-gpu.example.com' }).click();
  await page.getByTestId('show-topology').click();
  await expect(page.getByTestId('node-panel').getByTestId('node-host')).toContainText('GPU 0');
});

test('skript agenta jde stáhnout bez přihlášení', async ({ request }) => {
  const response = await request.get('/agent/nordvarr-agent.sh');
  expect(response.status()).toBe(200);
  expect(await response.text()).toContain('Nordvarr: hostový agent pro Linux');
});
