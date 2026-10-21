// Sestaví zip, rozbalí ho do prázdného dočasného adresáře a spustí nad ním
// vestavěný server PHP. Po testech server zastaví a adresář smaže.
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');

async function waitForServer(url, timeoutMs = 10000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await fetch(url, { redirect: 'manual' });
      if (res.status > 0) return;
    } catch {
      // server ještě neběží
    }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`Server na ${url} nenaběhl.`);
}

export default async function globalSetup(config) {
  const port = Number(process.env.E2E_PORT || 8765);
  const work = mkdtempSync(join(tmpdir(), 'nordvarr-e2e-'));
  const dist = join(work, 'dist');
  const version = readFileSync(join(root, 'VERSION'), 'utf8').trim();

  execFileSync(join(root, 'bin/build-release.sh'), { env: { ...process.env, DIST_DIR: dist }, stdio: 'inherit' });
  const site = join(work, 'site');
  execFileSync('unzip', ['-q', join(dist, `nordvarr-${version}.zip`), '-d', site]);

  const php = process.env.PHP_BINARY || 'php';
  const server = spawn(
    php,
    ['-S', `127.0.0.1:${port}`, '-t', join(site, 'nordvarr/public'), join(root, 'bin/dev-router.php')],
    { stdio: ['ignore', 'ignore', 'pipe'] },
  );
  let log = '';
  server.stderr.on('data', (chunk) => {
    log += chunk;
  });
  await waitForServer(`http://127.0.0.1:${port}/install.php`);

  // Údaje pro testy: kam se má instalovat a jak se přihlásit.
  process.env.E2E_SITE = site;
  writeFileSync(join(work, 'e2e.json'), JSON.stringify({ site }));

  return async () => {
    server.kill('SIGTERM');
    if (process.env.E2E_KEEP_LOG) {
      writeFileSync(join(root, 'test-results', 'php-server.log'), log);
    }
    rmSync(work, { recursive: true, force: true });
  };
}
