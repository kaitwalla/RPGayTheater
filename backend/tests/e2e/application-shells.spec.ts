import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createHash } from 'node:crypto';

const applications = [
    { path: '/control', title: 'RPGays Control', heading: 'Control' },
    { path: '/player', title: 'RPGays Player', heading: 'Player' },
    { path: '/presentation', title: 'RPGays Presentation', heading: 'Pair Presentation' },
] as const;
const controlSecret = process.env.PLAYWRIGHT_CONTROL_SECRET ?? 'local-development-secret-change-before-production';
const playerCode = 'LOADTEST';
const presentationToken = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const anonymousParticipantPaths = [
    '/api/participant/v1/map',
    '/api/participant/v1/roster',
    '/api/participant/v1/player-groups',
    '/api/participant/v1/messages',
    '/api/participant/v1/polls',
    '/api/participant/v1/rolls',
    '/api/participant/v1/roll-presets',
    '/api/participant/v1/npcs',
];

const waitForAnonymousParticipantBootstrap = (page: Page) =>
    Promise.all(anonymousParticipantPaths.map((path) => page.waitForResponse((response) => response.url().includes(path) && response.status() === 401)));

const waitForAnonymousPresentationBootstrap = (page: Page) =>
    Promise.all(
        ['/api/presentation/v1/state', '/api/presentation/v1/overlays'].map((path) =>
            page.waitForResponse((response) => response.url().includes(path) && response.status() === 401),
        ),
    );

const visualFingerprint = async (page: Page): Promise<string> =>
    createHash('sha256')
        .update(await page.screenshot({ animations: 'disabled', caret: 'hide' }))
        .digest('hex');

const screenshotExpectations = {
    presentation1920: 'e5f770ed78a7f2dff27d8e3474db9db3af04eb6d9bd6ee3a6d0945520ee18c85',
    controlDesktop: '5cb204cf3064c30fea713bff26836965d80bda1465ffe1082d1e79244a1711d0',
    mobilePlayer: {
        'mobile-chromium': ['86150ce8c6f10b88047be78bb37cfe5ac5ed8988abb914a99bf4e0679ba124cb'],
        'mobile-webkit': ['6787c9a4de04bc7fbff645a55658ba718c3b046ff7796feaa0bebdca046fb92a'],
    },
} as const;

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

test('Chromium screenshot regression preserves the 1920 Presentation and desktop Control shells', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto('/presentation');
    await expect(page.getByRole('heading', { name: 'Pair Presentation' })).toBeVisible();
    expect(await visualFingerprint(page)).toBe(screenshotExpectations.presentation1920);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/control');
    await expect(page.getByRole('heading', { name: 'Control' })).toBeVisible();
    expect(await visualFingerprint(page)).toBe(screenshotExpectations.controlDesktop);
});

test('Mobile Player screenshot regression preserves Android and iOS shell layouts', async ({ page }, testInfo) => {
    await page.goto('/player');
    await expect(page.getByRole('heading', { name: 'Player' })).toBeVisible();
    expect(screenshotExpectations.mobilePlayer[testInfo.project.name as keyof typeof screenshotExpectations.mobilePlayer]).toContain(
        await visualFingerprint(page),
    );
});

test('Control secret authentication creates a campaign and leaves the protected workspace', async ({ page }, testInfo) => {
    const campaignName = `Browser campaign ${testInfo.project.name} ${testInfo.retry}`;
    await page.goto('/control');

    await page.getByLabel('Control secret').fill(controlSecret);
    const workspaceLoaded = page.waitForResponse(
        (response) =>
            response.url().includes('/api/control/v1/campaigns') && response.request().method() === 'GET' && response.status() === 200,
        { timeout: 15_000 },
    );
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await workspaceLoaded;
    await expect(page.getByRole('heading', { name: 'Campaign drafts' })).toBeVisible({ timeout: 15_000 });

    await page.getByLabel('Campaign name').fill(campaignName);
    await page.getByRole('button', { name: 'Create campaign' }).click();
    await expect(page.getByLabel(`Name for ${campaignName}`)).toHaveValue(campaignName);

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByLabel('Control secret')).toBeVisible();
});

test('Chromium replaces media in the studio library without a server error', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'This integration flow runs once in Chromium.');
    const campaignName = `Replacement campaign ${testInfo.retry} ${Date.now()}`;
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlPjAcAAAAASUVORK5CYII=', 'base64');
    const alternatePng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL6NwAAAABJRU5ErkJggg==', 'base64');
    const replacementPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR42mNk+M/wHwAF/gL+Q1JiGQAAAABJRU5ErkJggg==', 'base64');

    await page.goto('/control');
    await page.getByLabel('Control secret').fill(controlSecret);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.getByLabel('Campaign name').fill(campaignName);
    await page.getByRole('button', { name: 'Create campaign' }).click();
    const campaignInput = page.getByLabel(`Name for ${campaignName}`);
    await expect(campaignInput).toHaveValue(campaignName);
    await campaignInput.locator('..').getByRole('link', { name: 'Open studio' }).click();
    await page.getByRole('button', { name: 'Media library' }).click();
    await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();

    await page.getByLabel('Media files').setInputFiles([
        { name: 'before.png', mimeType: 'image/png', buffer: png },
        { name: 'second.png', mimeType: 'image/png', buffer: alternatePng },
    ]);
    await expect(page.getByText('2 files ready to upload.')).toBeVisible();
    await page.getByRole('button', { name: 'Upload media' }).click();
    await expect(page.getByLabel('Label for before.png')).toBeVisible();
    await expect(page.getByLabel('Label for second.png')).toBeVisible();

    await page.getByLabel('Label for before.png').locator('..').getByRole('button', { name: 'Replace', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Replace media' })).toBeVisible();
    await page.getByLabel('Replacement media file').setInputFiles({ name: 'after.png', mimeType: 'image/png', buffer: replacementPng });
    await page.getByRole('button', { name: 'Replace everywhere' }).click();

    await expect(page.getByRole('dialog', { name: 'Replace media' })).toBeHidden();
    await expect(page.getByLabel('Label for after.png')).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
});

test('Chromium virtual passkey lifecycle registers, signs in, and revokes a Control credential', async ({ page }) => {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });

    await page.goto('/control');
    await page.getByLabel('Control secret').fill(controlSecret);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.getByRole('link', { name: 'Passkeys' }).click();
    await expect(page.getByRole('heading', { name: 'Passkeys', exact: true })).toBeVisible();

    await page.getByLabel('Control secret').fill(controlSecret);
    await page.getByRole('button', { name: 'Confirm secret' }).click();
    await expect(page.getByText('Confirmed until')).toBeVisible();
    await page.getByLabel('Passkey label').fill('Chromium test authenticator');
    await page.getByRole('button', { name: 'Add passkey' }).click();
    await expect(page.getByText('Chromium test authenticator')).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByLabel('Control secret')).toBeVisible();
    await page.getByRole('button', { name: 'Sign in with passkey' }).click();
    await expect(page.getByRole('heading', { name: 'Campaign drafts' })).toBeVisible();

    await page.getByRole('link', { name: 'Passkeys' }).click();
    await page.getByLabel('Control secret').fill(controlSecret);
    await page.getByRole('button', { name: 'Confirm secret' }).click();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Revoke' }).click();
    await expect(page.getByText('No passkeys are registered.')).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.getByRole('button', { name: 'Sign in with passkey' }).click();
    await expect(page.getByRole('alert')).toContainText('Passkey not recognized. It may have been removed from your account.');
});

test('Player and Spectator use isolated browser contexts with role-restricted session access', async ({ browser }, testInfo) => {
    const player = await browser.newContext();
    const spectator = await browser.newContext();
    const playerPage = await player.newPage();
    const spectatorPage = await spectator.newPage();
    const suffix = `${testInfo.project.name.replace(/[^a-z]/g, '').slice(0, 8)}${testInfo.retry}`;

    try {
        await test.step('open isolated Player and Spectator clients', async () => {
            const bootstraps = [waitForAnonymousParticipantBootstrap(playerPage), waitForAnonymousParticipantBootstrap(spectatorPage)];
            await Promise.all([playerPage.goto('/player'), spectatorPage.goto('/player')]);
            await Promise.all(bootstraps);
        });
        const join = async (page: typeof playerPage, displayName: string, role: 'player' | 'spectator') => {
            const joined = await page.evaluate(
                async ({ code, name, participantRole }) => {
                    const token = document.cookie
                        .split('; ')
                        .find((entry) => entry.startsWith('XSRF-TOKEN='))
                        ?.slice('XSRF-TOKEN='.length);
                    const response = await fetch('/api/participant/v1/join', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
                        },
                        body: JSON.stringify({ player_code: code, display_name: name, role: participantRole }),
                    });

                    return response.status;
                },
                { code: playerCode, name: displayName, participantRole: role },
            );
            expect(joined).toBe(201);
        };
        await test.step('join isolated Player and Spectator contexts', async () => {
            await Promise.all([join(playerPage, `Player ${suffix}`, 'player'), join(spectatorPage, `Spectator ${suffix}`, 'spectator')]);
        });

        const [playerRoster, spectatorRoster] = await test.step('read role-safe roster snapshots', async () =>
            Promise.all(
                [playerPage, spectatorPage].map((page) =>
                    page.evaluate(async () => {
                        const response = await fetch('/api/participant/v1/roster', { headers: { Accept: 'application/json' } });
                        return { status: response.status, body: await response.json() };
                    }),
                ),
            ));
        expect(playerRoster).toMatchObject({ status: 200, body: { data: { role: 'player', characters: [{ id: '00000000-0000-7000-8000-000000000007' }] } } });
        expect(spectatorRoster).toMatchObject({ status: 200, body: { data: { role: 'spectator' } } });

        const forbiddenClaim = await test.step('reject Spectator character claims', async () =>
            spectatorPage.evaluate(async () => {
                const token = document.cookie
                    .split('; ')
                    .find((entry) => entry.startsWith('XSRF-TOKEN='))
                    ?.slice('XSRF-TOKEN='.length);
                const response = await fetch('/api/participant/v1/claim', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
                    },
                    body: JSON.stringify({ player_character_id: '00000000-0000-7000-8000-000000000007' }),
                });

                return response.status;
            }));
        expect(forbiddenClaim).toBe(403);
    } finally {
        await Promise.all([player.close().catch(() => undefined), spectator.close().catch(() => undefined)]);
    }
});

test.describe('one-time Presentation pairing', () => {
    test.describe.configure({ retries: 0 });

    test('Chromium pairs Presentation and resolves a simultaneous Player claim without exposing it to Spectators', async ({ browser }, testInfo) => {
        const firstPlayer = await browser.newContext();
        const secondPlayer = await browser.newContext();
        const spectator = await browser.newContext();
        const presentation = await browser.newContext();
        const [firstPage, secondPage, spectatorPage, presentationPage] = await Promise.all([
            firstPlayer.newPage(),
            secondPlayer.newPage(),
            spectator.newPage(),
            presentation.newPage(),
        ]);
        const participantCommand = async (page: typeof firstPage, path: string, body: Record<string, string>) =>
            page.evaluate(
                async ({ requestPath, requestBody }) => {
                    const token = document.cookie
                        .split('; ')
                        .find((entry) => entry.startsWith('XSRF-TOKEN='))
                        ?.slice('XSRF-TOKEN='.length);
                    const response = await fetch(requestPath, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
                        },
                        body: JSON.stringify(requestBody),
                    });

                    return response.status;
                },
                { requestPath: path, requestBody: body },
            );
        const participantJoin = async (page: typeof firstPage, displayName: string, role: 'player' | 'spectator') => {
            const status = await participantCommand(page, '/api/participant/v1/join', { player_code: playerCode, display_name: displayName, role });
            expect(status).toBe(201);
        };

        try {
            const participantBootstraps = [
                waitForAnonymousParticipantBootstrap(firstPage),
                waitForAnonymousParticipantBootstrap(secondPage),
                waitForAnonymousParticipantBootstrap(spectatorPage),
            ];
            const presentationBootstrap = waitForAnonymousPresentationBootstrap(presentationPage);
            await Promise.all([firstPage.goto('/player'), secondPage.goto('/player'), spectatorPage.goto('/player'), presentationPage.goto('/presentation')]);
            await Promise.all([...participantBootstraps, presentationBootstrap]);
            const retrySuffix = `${testInfo.retry}`;
            await test.step('join independent Player and Spectator sessions', async () => {
                await Promise.all([
                    participantJoin(firstPage, `Claim racer one ${retrySuffix}`, 'player'),
                    participantJoin(secondPage, `Claim racer two ${retrySuffix}`, 'player'),
                    participantJoin(spectatorPage, `Claim observer ${retrySuffix}`, 'spectator'),
                ]);
            });

            const claimStatuses = await test.step('allow exactly one simultaneous Player claim', async () =>
                Promise.all([
                    participantCommand(firstPage, '/api/participant/v1/claim', { player_character_id: '00000000-0000-7000-8000-000000000007' }),
                    participantCommand(secondPage, '/api/participant/v1/claim', { player_character_id: '00000000-0000-7000-8000-000000000007' }),
                ]));
            expect(claimStatuses.sort()).toEqual([201, 422]);

            const spectatorRoster = await test.step('keep claim ownership private from Spectators', async () =>
                spectatorPage.evaluate(async () => {
                    const response = await fetch('/api/participant/v1/roster', { headers: { Accept: 'application/json' } });
                    return { status: response.status, body: await response.json() };
                }));
            expect(spectatorRoster).toMatchObject({
                status: 200,
                body: { data: { role: 'spectator', characters: [{ id: '00000000-0000-7000-8000-000000000007', claimed: true, claimed_by_me: false }] } },
            });

            await test.step('pair Presentation and verify its visible authenticated state', async () => {
                const paired = await participantCommand(presentationPage, '/api/presentation/v1/pair', { token: presentationToken });
                expect(paired).toBe(200);
                await presentationPage.goto('/presentation');
                await expect(presentationPage.getByText('No active scene')).toBeVisible();
                await expect(presentationPage.getByRole('button', { name: 'Enable sound' })).toBeVisible();
            });
        } finally {
            await Promise.all([
                firstPlayer.close().catch(() => undefined),
                secondPlayer.close().catch(() => undefined),
                spectator.close().catch(() => undefined),
                presentation.close().catch(() => undefined),
            ]);
        }
    });
});
