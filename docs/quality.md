# Quality gates

Run the same core quality gate that GitHub Actions requires from the repository
root:

```sh
docker compose --profile tools run --rm --build quality composer quality:ci
```

The same command runs on every pull request and push to `main` in GitHub
Actions. It is intended to catch changes likely to break the app without making
every deeper quality or advisory signal a required gate for a hobby project.

The CI browser job starts a disposable stack, migrates it, and runs the
Playwright/axe shell suite plus the Control secret campaign flow in
desktop Chromium, Firefox, and WebKit plus representative Android Chrome and
iOS Safari profiles. A dedicated localhost Chromium runner adds virtual-WebAuthn
coverage for passkey registration, sign-in, and revocation. Run the same browser
gate locally with:

```sh
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml up --build -d app
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml exec -T app php artisan migrate --force
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml exec -T app php artisan load-test:seed
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml --profile browser run --rm --build browser
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml --profile browser run --rm --build browser-passkey
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml --profile browser down --volumes --remove-orphans
```

The browser override intentionally publishes no host ports, so it can run next
to a developer's normal local services without touching their data volumes.

Run the isolated 30-participant performance gate with:

```sh
./scripts/run-load-test.sh
```

It starts a fresh private stack, creates a deterministic non-production
campaign/session fixture, then exercises simultaneous Player joins, a Control
poll and fog stroke, Presentation pairing, participant votes/messages/public
rolls, and resume-token reconnects. It fails if any request fails or ordinary
API-command p95 reaches 250 ms. The script removes only its
`rpgays-load-test` project.

The `quality` image pins PHP 8.4 and Node 24, installs development dependencies,
and can run either `composer quality:ci` or `composer quality:strict`.

The required `quality:ci` gate fails fast on all of the following:

- Laravel Pint formatting drift;
- Larastan/PHPStan at level 8 over `backend/app`;
- the Laravel test suite;
- OpenAPI generated type drift;
- Prettier and ESLint drift;
- `vue-tsc --noEmit` over the frontend application sources;
- the Vitest/Vue component and Node PWA frontend tests with coverage; and
- the production Vite build for all SPA entry points.

Run the strict gate when preparing a release, chasing regressions, or doing a
deeper maintenance pass:

```sh
docker compose --profile tools run --rm --build quality composer quality:strict
```

The strict gate adds backend Cobertura coverage thresholds, the semantic
Infection mutation gate, Composer and npm advisory audits, Knip dead-code
checks, and JSCPD duplication checks.

## Verified local release evidence

On 2026-07-20, the complete quality gate passed with 100 PHP tests / 1,889
assertions, 91.50% backend lines, 85.29% backend branches, 81% covered-code
mutation score, and 93.55% frontend statements / 80.82% branches. The fresh
browser stack also passed 30 scenarios across desktop Chromium, Firefox,
WebKit, Android Chrome, and iOS Safari profiles, with no skipped tests. It
includes a Chromium virtual-authenticator passkey registration, login, and
revocation lifecycle plus deterministic screenshot fingerprints for 1920×1080
Presentation, desktop Control, Android Player, and iOS Player shells.
The isolated 30-participant load rehearsal passed all 277 checks with zero
failed requests and a 51.10 ms ordinary-command p95. The isolated backup and
restore rehearsal restored both its database and object-storage marker and
returned a fully ready application.
The service-interruption rehearsal also passed: PostgreSQL, Redis, MinIO,
worker degradation/recovery plus pending-outbox retry behavior.

Hosted Pusher credentials and a production deployment are external
prerequisites, so they are not represented as local pass evidence. Complete
the Pusher connectivity, real-device audio, and production restore checklist
with provisioned credentials before release.

PHPStan cache output is kept under `backend/storage/framework/phpstan` and is
not versioned. Do not add a baseline or ignore errors merely to preserve a
green build: model and API types should be made precise instead.

## Raising strictness

Level 8 is the enforced starting point while the authoring model is built.
Before opening the live-session aggregate, run level 9 locally and resolve
every finding in the changed domain area. Raise the committed `level` to 9
once the whole application is clean. After that, evaluate PHPStan's stricter
rules only when the added signal is actionable for Laravel's Eloquent and
request boundaries; document each accepted rule set and keep it enforced in
this same command.
