# Deployment and release runbook

This runbook is for deploying the three same-origin applications: Control,
Presentation, and Player/Spectator. It assumes PostgreSQL, Redis, an
S3-compatible object store, and a Pusher-compatible broadcaster are available
to the Laravel application.

## Required production configuration

Set `APP_ENV=production`, `APP_DEBUG=false`, and an HTTPS `APP_URL`. Provide
strong, unique values for `APP_KEY`, `CONTROL_SECRET`, database credentials,
Redis credentials, object-storage credentials, and the broadcast app key and
secret. For hosted Pusher staging or production, set these Laravel runtime
environment variables in Forge:
`BROADCAST_CONNECTION=pusher` plus `PUSHER_APP_ID`, `PUSHER_APP_KEY`,
`PUSHER_APP_SECRET`, and `PUSHER_APP_CLUSTER` (or the explicitly configured
host, port, and scheme). `CONTROL_SECRET` is both the recovery login and the confirmation factor
for passkey changes; keep it in the production secret manager and never in an
image, Compose file, or browser-visible environment variable.

The Compose default for `APP_KEY` is only for non-production local startup.
Production deployments must inject their own unique application key.

Use HTTPS at the edge. The browser realtime client reads public Pusher
connection data from Laravel's rendered SPA shell, so Forge production builds
do not need duplicate `VITE_PUSHER_*` variables. Confirm object storage has a
private bucket, lifecycle rules appropriate to immutable revision assets, and a
narrow service account limited to that bucket.

## Release procedure

1. Start from the intended commit with no local changes. The protected branch
   must have passed the GitHub Actions **Quality** workflow.
2. Deploy through Forge from the intended commit and run the frontend build on
   the server. Do not expose secrets through Vite variables or build output.
3. Back up PostgreSQL and the immutable object-store data, then record the
   backup identifiers in the release log. Follow the restore rehearsal in
   [operations.md](operations.md#backup-and-restore-rehearsal).
4. Restart the web process, queue worker, and scheduler together. Keep at least
   one queue worker active while the release is rolled out.
5. Run `php artisan migrate --force` from the new release. Migrations
   must be backward-compatible for the duration of a rolling deployment.
   For the campaign-studio cutover only, this release intentionally starts with
   an empty authoring library: after verifying the backups, run
   `php artisan campaigns:reset-authoring --force` and explicitly confirm its
   prompt. It removes campaign drafts, published revisions, live sessions,
   campaign events, and campaign media while preserving users, passkeys, and
   application configuration. Do not run it where campaign history is needed.
6. Verify `GET /live` returns `200`, then verify `GET /ready` returns `200`
   with every check set to `ok`. Record the `X-Request-Id` from each check, then
   confirm Control login, a participant read-only page load, and a Presentation
   pairing page load over HTTPS.
7. Watch structured application logs by request ID and the Control realtime-delivery status
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
domain record. It refuses production, local, and Reverb-backed configurations,
so normal CI remains deterministic and uses local Reverb instead. Record the
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
