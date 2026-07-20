# Deployment and release runbook

This runbook is for deploying the three same-origin applications: Control,
Presentation, and Player/Spectator. It assumes PostgreSQL, Redis, an
S3-compatible object store, and a Reverb-compatible broadcaster are available
to the application containers.

## Required production configuration

Set `APP_ENV=production`, `APP_DEBUG=false`, and an HTTPS `APP_URL`. Provide
strong, unique values for `APP_KEY`, `CONTROL_SECRET`, database credentials,
Redis credentials, object-storage credentials, and the broadcast app key and
secret. `CONTROL_SECRET` is both the recovery login and the confirmation factor
for passkey changes; keep it in the production secret manager and never in an
image, Compose file, or browser-visible environment variable.

The Compose default for `APP_KEY` is only for non-production local startup.
Production deployments must inject their own unique application key.

Use HTTPS at the edge and set the Reverb host, port, scheme, and allowed
origins to the public deployment values. Confirm object storage has a private
bucket, lifecycle rules appropriate to immutable revision assets, and a narrow
service account limited to that bucket.

## Release procedure

1. Start from the intended commit with no local changes. The protected branch
   must have passed the GitHub Actions **Quality** workflow.
2. Build the production image, supplying only public Vite Reverb configuration
   as build arguments. Do not supply secrets as build arguments.
3. Back up PostgreSQL and the immutable object-store data, then record the
   backup identifiers in the release log. Follow the restore rehearsal in
   [operations.md](operations.md#backup-and-restore-rehearsal).
4. Deploy the new `app`, `worker`, `scheduler`, and `reverb` images together.
   Keep at least one queue worker active while the release is rolled out.
5. Run `php artisan migrate --force` from the new application image. Migrations
   must be backward-compatible for the duration of a rolling deployment.
6. Verify `GET /ready` returns `200` with every check set to `ok`, then confirm
   Control login, a participant read-only page load, and a Presentation pairing
   page load over HTTPS.
7. Watch structured application logs and the Control realtime-delivery status
   for failed outbox messages before declaring the release complete.

## Rollback

Roll back application, worker, scheduler, and Reverb images as one release
unit. Do not restore a database backup merely to roll back application code:
first assess whether the migration is reversible without discarding live
session data. If data restoration is required, restore both PostgreSQL and the
matching object-store backup into an isolated environment, validate `/ready`,
and obtain an operator decision before directing production traffic there.

## Release evidence

Record the commit SHA, image digests, migration output, backup identifiers,
`/ready` result, smoke-test operator, and the observed outbox status. Keep this
record with the release so a later incident can correlate runtime events with
the deployed revision.
