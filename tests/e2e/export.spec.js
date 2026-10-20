import { test, expect } from '@playwright/test';
import { createObject, loginOk } from './helpers.js';

const CANARY = 'Kanarek-E2E-Heslo-42';

test('export pro LLM: tlačítko na kartě, Kopírovat do schránky, tajné údaje maskované', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await loginOk(page);
  const url = await createObject(page, 'llm-e2e.example.com', 'server');
  await page.locator('#attr-key').fill('root_password');
  await page.locator('#attr-value').fill(CANARY);
  await page.getByRole('button', { name: 'Uložit atribut' }).click();
  await page.locator('#attr-key').fill('ip');
  await page.locator('#attr-value').fill('192.0.2.77');
  await page.getByRole('button', { name: 'Uložit atribut' }).click();

  await page.goto(url);
  await page.getByTestId('llm-export').click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Export pro LLM: llm-e2e.example.com');
  const text = page.getByTestId('llm-text');
  await expect(text).toHaveValue(/^# Kontext objektu: llm-e2e\.example\.com \(Server\)/);
  await expect(text).toHaveValue(/- root_password: \*\*\*/);
  await expect(text).toHaveValue(/- ip: 192\.0\.2\.77/);
  expect(await page.content()).not.toContain(CANARY);

  await page.getByTestId('copy').click();
  await expect(page.getByText('Zkopírováno do schránky.')).toBeVisible();
  const clipboard = await page.evaluate(() => navigator.clipboard.readText());
  expect(clipboard).toBe(await text.inputValue());
  expect(clipboard).not.toContain(CANARY);
});
