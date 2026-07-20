import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const applications = [
    { path: '/control', title: 'RPGays Control', heading: 'Control' },
    { path: '/player', title: 'RPGays Player', heading: 'Player' },
    { path: '/presentation', title: 'RPGays Presentation', heading: 'Pair Presentation' },
] as const;
const controlSecret = process.env.PLAYWRIGHT_CONTROL_SECRET ?? 'local-development-secret-change-before-production';

for (const application of applications) {
    test(`${application.path} renders its accessible unauthenticated shell`, async ({ page }) => {
        await page.goto(application.path);

        await expect(page).toHaveTitle(application.title);
        await expect(page.locator('main')).toBeVisible();
        await expect(page.getByRole('heading', { name: application.heading })).toBeVisible();

        const results = await new AxeBuilder({ page }).include('main').analyze();
        expect(results.violations).toEqual([]);
    });
}

test('Control secret authentication reaches and leaves the protected campaign workspace', async ({ page }) => {
    await page.goto('/control');

    await page.getByLabel('Control secret').fill(controlSecret);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Campaign drafts' })).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByLabel('Control secret')).toBeVisible();
});
