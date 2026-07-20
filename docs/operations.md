# Local operations

## Start the stack

Set a non-production `CONTROL_SECRET` in your shell, then start the services:

```sh
docker compose up --build -d
docker compose exec app php artisan migrate --force
```

The Control application is at `http://localhost:8000/control`; liveness is at
`http://localhost:8000/live` and dependency readiness is at
`http://localhost:8000/ready`. PostgreSQL, Redis, and MinIO are available on
their standard local ports. MinIO's console is at `http://localhost:9001`.

Every HTTP response includes an `X-Request-Id`. Supply a safe value of your
own when running a release or incident check, then use that value to correlate
the structured JSON logs emitted by the Compose services. `/live` confirms the
application process is serving requests without checking dependencies; use
`/ready` for traffic-routing and release decisions because it also verifies the
database, Redis cache, and object storage.

The `app`, `worker`, and `scheduler` services use the same image. Application
commands must be run through `docker compose exec app`; frontend commands must
be run with the pinned Node 24 container, for example:

```sh
docker run --rm -v "$PWD/backend:/app" -w /app node:24-alpine npm run build
```

Run the complete formatting, static-analysis, test, and frontend-build gate
before committing:

```sh
docker compose --profile tools run --rm quality
```

See [quality.md](quality.md) for its exact checks and the PHPStan strictness
ratchet. See [deployment.md](deployment.md) for production configuration,
release, rollback, and evidence requirements.

## Backup and restore rehearsal

Create a database backup and copy the object-storage volume while the stack is
quiesced. Test restores in a separate Compose project name, then run migrations
and verify `/ready` before directing traffic to it. Do not treat a database-only
backup as sufficient: immutable revision assets live in object storage.

```sh
docker compose exec -T postgres pg_dump -U rpgays -Fc rpgays > rpgays.dump
docker compose exec -T postgres pg_restore -U rpgays -d rpgays --clean --if-exists < rpgays.dump
```

Production backups must encrypt database dumps and object-store copies, retain
them under a documented policy, and include an operator-recorded restore test.
