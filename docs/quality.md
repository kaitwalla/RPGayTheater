# Quality gate

Run the complete local quality gate from the repository root:

```sh
docker compose --profile tools run --rm quality
```

The same command runs on every pull request and push to `main` in GitHub
Actions. It also executes a source-integrity test that rejects provisional
implementation markers in shippable application, route, and frontend sources.

The CI browser job starts a disposable stack, migrates it, and runs the
Playwright/axe shell suite plus the Control secret login/logout flow in
Chromium, Firefox, and WebKit. Run the same browser gate locally with:

```sh
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml up --build -d app
docker compose -p rpgays-browser-test -f docker-compose.yml -f docker-compose.browser.yml exec -T app php artisan migrate --force
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
- the Laravel test suite; and
- the Vitest/Vue component and Node PWA frontend tests; and
- the production Vite build for all SPA entry points.

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
