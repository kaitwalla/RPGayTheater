# Quality gate

Run the complete local quality gate from the repository root:

```sh
docker compose --profile tools run --rm quality
```

The same command runs on every pull request and push to `main` in GitHub
Actions. It also executes a source-integrity test that rejects provisional
implementation markers in shippable application, route, and frontend sources.

The CI browser job starts a disposable stack, migrates it, and runs the
Playwright/axe shell suite plus the Control secret campaign flow in
desktop Chromium, Firefox, and WebKit plus representative Android Chrome and
iOS Safari profiles. Run the same browser gate locally with:

```sh
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml up --build -d app
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml exec -T app php artisan migrate --force
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml exec -T app php artisan load-test:seed
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml --profile browser run --rm --build browser
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml down --volumes --remove-orphans
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
and runs `composer quality`. That command fails fast on all of the following:

- Laravel Pint formatting drift;
- Larastan/PHPStan at level 8 over `backend/app`;
- the Laravel test suite, 90% line / 85% branch Cobertura path coverage, and
  the focused semantic Infection mutation gate;
- Composer and npm dependency advisories at or above the configured severity;
- Prettier, ESLint, Knip, and JSCPD drift;
- `vue-tsc --noEmit` over the frontend application sources;
- the Vitest/Vue component and Node PWA frontend tests with 85% line and 80%
  branch coverage; and
- the production Vite build for all SPA entry points.

## Verified local release evidence

On 2026-07-20, the complete quality gate passed with 100 PHP tests / 1,889
assertions, 91.47% backend lines, 85.23% backend branches, 81% covered-code
mutation score, and 93.55% frontend statements / 80.82% branches. The fresh
browser stack also passed 26 scenarios across desktop Chromium, Firefox,
WebKit, Android Chrome, and iOS Safari profiles; four projects intentionally
skip the single-use Presentation pairing race after Chromium exercises it.
The isolated 30-participant load rehearsal passed all 277 checks with zero
failed requests and a 51.32 ms ordinary-command p95. The isolated backup and
restore rehearsal restored both its database and object-storage marker and
returned a fully ready application.
The service-interruption rehearsal also passed: PostgreSQL, Redis, MinIO,
worker, and Reverb degradation/recovery plus pending-outbox retry behavior.

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
