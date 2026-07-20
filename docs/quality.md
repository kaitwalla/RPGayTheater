# Quality gate

Run the complete local quality gate from the repository root:

```sh
docker compose --profile tools run --rm quality
```

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
