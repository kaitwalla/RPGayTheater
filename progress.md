# Implementation Progress

Last updated: 2026-07-19

## Completed

### 1. Laravel/Vue foundation — `53106e0`

- Created the Laravel 13 application in `backend/`.
- Added the three Vue/Vite entry points for Control, Presentation, and
  Participant, plus shared frontend utilities.
- Preserved the product and implementation plans at the repository root.

### 2. Control campaign foundation — `d2fbecc`

- Added secret-based Control login with constant-time comparison, session
  rotation, CSRF-protected same-origin requests, rate limiting, and production
  secret-strength validation.
- Built a Vue Control interface for creating, renaming, listing, and archiving
  campaign drafts.
- Implemented campaign draft revision numbers and optimistic concurrency:
  commands require `command_id` and `expected_revision`; stale writes return
  `409` with current state.
- Added idempotency records, append-only audit events, and transactional outbox
  rows for every campaign mutation.
- Added feature coverage for authentication, authorization, replay safety,
  stale updates, and event/outbox writes.

### 3. Containerized operations — `efc3b20`

- Added a multi-stage PHP 8.4/Composer/Node 24 application image.
- Added Docker Compose services for application, worker, scheduler,
  PostgreSQL, Redis, MinIO, and MinIO bucket initialization.
- Added `/ready` dependency readiness reporting and an operations/backup guide.
- Added a Docker ignore file so host dependency/cache artifacts do not enter the
  production image.

### 4. Immutable campaign revisions — `8411df1`

- Added immutable, UUID-backed campaign revisions with schema-versioned JSON
  manifests and SHA-256 checksums.
- Added the authenticated publish command, with idempotent replay and
  optimistic-concurrency checks.
- Added publish-flow feature coverage, including manifest checksum validation.

## Verification completed

- Laravel feature suite passed in the Composer/PHP container (6 tests, 30
  assertions before the revision test; the campaign/revision suite passed with
  5 tests and 33 assertions after it was added).
- All three Vite entry points built successfully in the pinned Node 24
  container.
- `docker compose config --quiet` passed.
- `docker compose build app` passed after validating the final PHP/Node image
  stages.

## Current architecture

- Backend: Laravel 13, PHP 8.4-compatible, SQLite for isolated tests and
  PostgreSQL/Redis/MinIO in Compose.
- Frontend: Vue 3, Vue Router, Pinia, Vite; Control is currently the exposed
  working SPA. Presentation and Participant entry points are built but are not
  exposed until their authenticated session flows are implemented.
- State integrity: relational campaign data is authoritative; command replay,
  optimistic revisions, audit events, and outbox records are in place for the
  initial campaign aggregate.

## Next steps

Implement in this order, committing after each verified section:

1. **Authored content model and asset pipeline**
   - Add assets, PCs, NPCs/states, scenes, stage presets, maps/fog/tokens, and
     music/SFX/video/dice records.
   - Add direct multipart object-storage uploads, checksum keys, MIME/magic
     verification, metadata extraction, signed reads, and Control editors.
   - Expand publish validation so manifests cover the full authored graph.

2. **Revision adoption and packages**
   - Add live-reference compatibility preflight and explicit revision adoption.
   - Implement ZIP64 content export/import with complete archive validation,
     atomic rollback, and round-trip/malicious-package fixtures.

3. **Live-session aggregate and delivery**
   - Add session lifecycle, progress resume/fresh behavior, session codes,
     participant resume tokens, PC claims, named groups, and display pairing.
   - Add revisioned presentation/map/overlay snapshots, transactional outbox
     dispatch, Pusher/Reverb adapter, reconnect polling, and degraded status.

4. **Presentation and Control live tools**
   - Add shared Konva stage renderer, media-engine abstraction, standby/Go,
     scene and NPC staging, transitions, audio, video policies, and overlays.

5. **Player/Spectator interactions and maps**
   - Add PWA session flows, role policies, notes/messages/polls/dice, fog,
     read-only participant maps, and Control-only token editing.

6. **Hardening and release**
   - Add OpenAPI generation, passkeys, browser/E2E/accessibility suites,
     load/resilience tests, monitoring, backups/restore rehearsal, and the full
     quality gate defined in `plan.md`.
