import { expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import { createServer as createTcpServer } from 'node:net';
import { join } from 'node:path';
import { promisify } from 'node:util';

export const ADMIN_EMAIL = 'admin@example.com';
export const ADMIN_PASSWORD = 'e2e-tajne-heslo';

export async function login(page, password = ADMIN_PASSWORD) {
  await page.goto('/login');
  await page.getByLabel('E-mail').fill(ADMIN_EMAIL);
  await page.getByLabel('Heslo').fill(password);
  await page.getByRole('button', { name: 'Přihlásit se' }).click();
}

export async function loginOk(page) {
  await login(page);
  await expect(page.getByRole('heading', { level: 1, name: 'Přehled' })).toBeVisible();
}

// Worker asynchronně: synchronní spuštění by zablokovalo lokální přijímač webhooků v procesu testu.
export async function runWorker() {
  const php = process.env.PHP_BINARY || 'php';
  await promisify(execFile)(php, [join(process.env.E2E_SITE, 'nordvarr/bin/nordvarr.php'), 'worker']);
}

// Volný port, na kterém nic neposlouchá (kontrola TCP na něm selže s tcp_refused).
export async function closedPort() {
  return new Promise((resolve) => {
    const server = createTcpServer();
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
  });
}

export async function createObject(page, name, type) {
  await page.goto('/objects/new');
  await page.getByLabel('Typ').selectOption(type);
  await page.getByLabel('Název').fill(name);
  await page.getByRole('button', { name: 'Uložit' }).click();
  await expect(page.getByTestId('object-name')).toHaveText(name);
  return page.url();
}

export async function addTcpCheck(page, objectUrl) {
  await page.goto(objectUrl);
  await page.getByTestId('add-check').click();
  await page.getByLabel('Typ').selectOption('tcp');
  await page.locator('#cfg-tcp-host').fill('127.0.0.1');
  await page.locator('#cfg-tcp-port').fill(String(await closedPort()));
  await page.getByLabel('Počet selhání před změnou stavu').fill('1');
  await page.getByRole('button', { name: 'Uložit' }).click();
  await expect(page.getByTestId('flash')).toContainText('Kontrola byla vytvořena');
}
