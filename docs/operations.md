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
database, Redis cache, Redis-backed realtime queue, and object storage.

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

Run the disposable rehearsal from a clean checkout:

```sh
./scripts/rehearse-backup-restore.sh
```

It creates source and restore stacks named `rpgays-rehearsal-source` and
`rpgays-rehearsal-restore`, with no host ports or shared volumes. It writes one
database marker and one private MinIO object, exports PostgreSQL in custom
format plus the object-store bucket, restores both to the second stack, runs
migrations, and verifies the marker and `/ready`. Both stacks, their named
volumes, and its temporary archive are removed on exit. The same procedure runs
in CI.

This is a recovery verification, not a production backup command. Production
backups must quiesce or coordinate writes, encrypt database dumps and
object-store copies, retain them under a documented policy, and preserve backup
identifiers outside the disposable rehearsal. Restore those backups only into a
separate environment before an operator approves production traffic.

## Service-interruption rehearsal

Run the disposable resilience gate from a clean checkout:

```sh
./scripts/rehearse-service-interruptions.sh
```

It creates an isolated, no-host-port Compose project, then independently stops
PostgreSQL, Redis, MinIO, the queue worker, and local Reverb (the Pusher
protocol adapter). It verifies `/ready` reports the unavailable database,
cache/queue, or storage dependency; verifies queued and failed realtime
delivery remain visible in the transactional outbox; and verifies those events
dispatch after each service returns. It removes all test-only volumes on exit.
