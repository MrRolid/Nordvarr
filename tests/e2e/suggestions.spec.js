import { test, expect } from '@playwright/test';
import { createObject, loginOk } from './helpers.js';

test('doporučené kontroly na kartě objektu a hromadně v inventáři', async ({ page }) => {
  await loginOk(page);
  await createObject(page, 'navrh-e2e.example.com', 'website');
  const suggestions = page.getByTestId('suggestions');
  await expect(suggestions).toContainText('https://navrh-e2e.example.com/');
  await expect(suggestions).toContainText('navrh-e2e.example.com:443');
  await expect(suggestions).toContainText('založí objekt Doména');
  await page.getByTestId('add-suggested').click();
  await expect(page.getByTestId('flash')).toContainText('Přidáno kontrol: 3');
  await expect(page.getByTestId('checks')).toContainText('GET https://navrh-e2e.example.com/');
  // Expirace domény je na objektu domény, web na něm závisí.
  await expect(page.getByTestId('relationships')).toContainText('example.com');
  await expect(page.getByTestId('suggestions')).toHaveCount(0);

  await createObject(page, 'navrh-e2e.example.net', 'domain');
  await page.goto('/inventory?q=navrh-e2e.example.net');
  await page.getByRole('checkbox', { name: /navrh-e2e\.example\.net/ }).check();
  await page.getByTestId('bulk-suggested').click();
  await expect(page.getByTestId('flash')).toContainText('Přidáno kontrol: 1 u 1 objektů');
  await page.getByRole('link', { name: 'navrh-e2e.example.net' }).click();
  await expect(page.getByTestId('checks')).toContainText('navrh-e2e.example.net');
});
