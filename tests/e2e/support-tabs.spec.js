const { test, expect } = require('@playwright/test');

const USERNAME = process.env.SUPPORT_E2E_USERNAME || 'admin';
const PASSWORD = process.env.SUPPORT_E2E_PASSWORD || '';
const PROJECT_ID = process.env.SUPPORT_E2E_PROJECT_ID || '4';

async function waitForApp(page) {
  await expect
    .poll(async () => {
      try {
        const response = await page.request.get('/login.php');
        return response.status();
      } catch (error) {
        return 0;
      }
    }, {
      timeout: 60_000,
      intervals: [1_000, 2_000, 3_000],
    })
    .toBe(200);
}

async function login(page) {
  if (!PASSWORD) {
    throw new Error('SUPPORT_E2E_PASSWORD is required for support E2E tests.');
  }

  await waitForApp(page);
  await page.goto('/login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="username"]').fill(USERNAME);
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole('button', { name: '認証を開始' }).click();
  await page.waitForLoadState('domcontentloaded');
  await expect(page).not.toHaveURL(/login\.php$/, { timeout: 30_000 });
}

test.describe('support.php tabs', () => {
  let consoleErrors = [];
  let notFoundRequests = [];

  test.beforeEach(async ({ page }) => {
    consoleErrors = [];
    notFoundRequests = [];

    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    page.on('response', (response) => {
      if (response.status() === 404) {
        notFoundRequests.push(`${response.status()} ${response.url()}`);
      }
    });

    await login(page);
    await page.goto(`/support.php?project_id=${PROJECT_ID}&tab=overview`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#tab-overview')).toBeVisible();
  });

  test.afterEach(async () => {
    expect(consoleErrors, `Console errors detected:\n${consoleErrors.join('\n')}`).toEqual([]);
    expect(notFoundRequests, `404 responses detected:\n${notFoundRequests.join('\n')}`).toEqual([]);
  });

  test('switches core support tabs', async ({ page }) => {
    const tabs = [
      { button: '#btn-overview', panel: '#tab-overview' },
      { button: '#btn-faqs', panel: '#tab-faqs' },
      { button: '#btn-memory', panel: '#tab-memory' },
      { button: '#btn-materials', panel: '#tab-materials' },
      { button: '#btn-pdf', panel: '#tab-pdf' },
      { button: '#btn-csv', panel: '#tab-csv' },
    ];

    for (const { button, panel } of tabs) {
      await page.click(button);
      await expect(page.locator(panel)).toBeVisible();
    }
  });

  test('expands knowledge cards as details accordion', async ({ page }) => {
    await page.click('#btn-faqs');
    const cards = page.locator('#faq-list-container details[data-faq-card]');
    await expect(cards.first()).toBeVisible();
    expect(await cards.count()).toBeGreaterThan(0);

    await cards.nth(0).locator('summary').click();
    await expect(cards.nth(0)).toHaveJSProperty('open', true);

    await cards.nth(1).locator('summary').click();
    await expect(cards.nth(1)).toHaveJSProperty('open', true);

    const openCount = await page.locator('#faq-list-container details[data-faq-card][open]').count();
    expect(openCount).toBeGreaterThan(0);
  });

  test('shows memory update fields', async ({ page }) => {
    await page.click('#btn-memory');

    await expect(page.locator('#tab-memory')).toBeVisible();
    await expect(page.locator('#memory-readme')).toBeVisible();
    await expect(page.locator('#memory-agents')).toBeVisible();
    await expect(page.locator('#memory-todo')).toBeVisible();
    await expect(page.getByText('現在の手動メモ（更新対象）').first()).toBeVisible();
    await expect(page.getByText('編集・更新フォーム').first()).toBeVisible();
  });

  test('shows pdf iframe or missing-file message', async ({ page }) => {
    await page.click('#btn-pdf');

    const pdfCards = page.locator('#pdf-document-list > details');
    await expect(pdfCards.first()).toBeVisible();

    const visibleIframeCount = await page.locator('#pdf-document-list iframe').count();
    const missingMessageCount = await page.getByText('プレビューを表示できません').count();

    expect(visibleIframeCount > 0 || missingMessageCount > 0).toBeTruthy();

    const oldPdfCard = page.locator('#pdf-document-list details').filter({ hasText: 'PDF図面2.pdf' }).first();
    await expect(oldPdfCard).toBeVisible();
    await expect(oldPdfCard.locator('iframe')).toHaveCount(1);

    const missingPreviewCards = page.locator('#pdf-document-list details').filter({ hasText: 'プレビューを表示できません' });
    if (await missingPreviewCards.count()) {
      await expect(missingPreviewCards.first()).toContainText('プレビューを表示できません');
    }
  });
});
