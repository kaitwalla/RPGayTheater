import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const applications = [
    { path: '/control', title: 'RPGays Control', heading: 'Control' },
    { path: '/player', title: 'RPGays Player', heading: 'Player' },
    { path: '/presentation', title: 'RPGays Presentation', heading: 'Pair Presentation' },
] as const;

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
