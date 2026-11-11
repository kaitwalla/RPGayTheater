# Deployment and release runbook

This runbook is for deploying the three same-origin applications: Control,
Presentation, and Player/Spectator. The production Compose stack runs Nginx,
PHP-FPM, the queue worker, scheduler, PostgreSQL, Redis, and MinIO. Hosted
Pusher provides realtime events. The original `docker-compose.yml` remains a
local development stack.

## Docker deployment

Provide a host with Docker Compose and an HTTPS reverse proxy. Point the
application hostname at `127.0.0.1:8080` and the object-storage hostname at
`127.0.0.1:9000`; both Compose ports are bound to loopback. The proxy must
preserve the request host and route the storage hostname to MinIO without
rewriting paths or query strings, since browser upload URLs are signed for that
hostname. Use HTTPS for both hostnames.

Copy `.env.production.example` to `.env.production`, replace all placeholders,
and keep it out of source control. Generate `APP_KEY` as
`base64:$(openssl rand -base64 32)`. `CONTROL_SECRET`, database password, and
both MinIO credential pairs must be independent random values. Configure the
Pusher app and cluster. The startup configuration rejects missing variables;
the example placeholders are documentation, not usable production secrets.

From the repository root, run:

```sh
docker compose --env-file .env.production -f docker-compose.production.yml up --build -d
docker compose --env-file .env.production -f docker-compose.production.yml exec app php artisan migrate --force
curl --fail https://theater.example.com/ready
```

Replace the example hostname in the check. The stack creates a private MinIO
bucket and a bucket-scoped application account. MinIO allows browser CORS
requests from `APP_URL`. PostgreSQL, Redis, and MinIO use named volumes;
do not delete those volumes during releases. The MinIO root account is only
used by the MinIO service and one-time bootstrap service, while the application
uses `MINIO_APP_USER` and `MINIO_APP_PASSWORD`.

The web and storage loopback ports, the proxy's TLS configuration, DNS, Pusher
credentials, and backup destination are host-specific. Confirm these before
directing production traffic to the stack. Build the image for the server's
architecture; the browser test image is not part of this deployment.

## Required production configuration

The Compose file sets `APP_ENV=production`, `APP_DEBUG=false`, and secure
session cookies. Provide an HTTPS `APP_URL` and the required values listed in
the template. For hosted Pusher, the Laravel runtime uses
`BROADCAST_CONNECTION=pusher` plus `PUSHER_APP_ID`, `PUSHER_APP_KEY`,
`PUSHER_APP_SECRET`, and `PUSHER_APP_CLUSTER` (or the explicitly configured
host, port, and scheme). `CONTROL_SECRET` is both the recovery login and the confirmation factor
for passkey changes; keep it in the production secret manager and never in an
image, Compose file, or browser-visible environment variable.

The Compose default for `APP_KEY` is only for non-production local startup.
Production deployments must inject their own unique application key.

Use HTTPS at the edge. The browser realtime client reads public Pusher
connection data from Laravel's rendered SPA shell, so production builds do not
need duplicate `VITE_PUSHER_*` variables. Configure lifecycle rules appropriate
to immutable revision assets.

The MinIO service sets its CORS origin to `APP_URL`, including browser `PUT`
and preflight requests. The application policy includes multipart parts
listing, which the server uses to complete uploads. Browser responses do not
need to expose `ETag`.

## Release procedure

1. Start from the intended commit with no local changes. The protected branch
   must have passed the GitHub Actions **Quality** workflow.
2. Back up PostgreSQL and the immutable object-store data, then record the
   backup identifiers in the release log. Follow the restore rehearsal in
   [operations.md](operations.md#backup-and-restore-rehearsal).
3. Build and start the production Compose stack from the intended commit with
   `docker compose --env-file .env.production -f docker-compose.production.yml up --build -d`.
   This replaces the web process, queue worker, and scheduler. Do not expose
   secrets through Vite variables or build output.
4. Run `docker compose --env-file .env.production -f docker-compose.production.yml exec app php artisan migrate --force`.
   Migrations
   must be backward-compatible for the duration of a rolling deployment.
   For the campaign-studio cutover only, this release intentionally starts with
   an empty authoring library: after verifying the backups, run
   `php artisan campaigns:reset-authoring --force` and explicitly confirm its
   prompt. It removes campaign drafts, published revisions, live sessions,
   campaign events, and campaign media while preserving users, passkeys, and
   application configuration. Do not run it where campaign history is needed.
5. Verify `GET /live` returns `200`, then verify `GET /ready` returns `200`
   with every check set to `ok`. Record the `X-Request-Id` from each check, then
   confirm Control login, a participant read-only page load, and a Presentation
   pairing page load over HTTPS.
6. Watch structured application logs by request ID and the Control realtime-delivery status
   for failed outbox messages before declaring the release complete.

## Hosted Pusher staging smoke test

After deploying to staging with `APP_ENV=staging` and
`BROADCAST_CONNECTION=pusher`, run this command from one staging application
container:

```sh
php artisan realtime:pusher-smoke
```

It sends one ephemeral event to a random private channel through the configured
Pusher credentials. A successful command proves the deployed application can
authenticate and publish to Pusher; it does not write an outbox, audit, or
domain record. It refuses production and non-Pusher configurations. Record the
probe ID with the release evidence.

## Rollback

Roll back application code, queue workers, and scheduler as one release unit.
Do not restore a database backup merely to roll back application code:
first assess whether the migration is reversible without discarding live
session data. If data restoration is required, restore both PostgreSQL and the
matching object-store backup into an isolated environment, validate `/ready`,
and obtain an operator decision before directing production traffic there.

## Release evidence

Record the commit SHA, image digests, migration output, backup identifiers,
`/ready` result, smoke-test operator, and the observed outbox status. Keep this
record with the release so a later incident can correlate runtime events with
the deployed revision.
