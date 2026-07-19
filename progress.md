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

### 5. Enforced quality baseline

- Added Larastan/PHPStan 2 at enforced level 8 for application code, with no
  baseline or ignored findings.
- Added the `composer quality` command and a PHP 8.4/Node 24 Compose quality
  image that runs formatting, static analysis, backend tests, and all SPA
  production builds together.
- Documented the repeatable command and an explicit level-9 strictness
  ratchet in `docs/quality.md`.

### 6. Authored-content foundation — `8e0a431` through `2ef6988`

- Added a private, direct multipart asset pipeline backed by MinIO/S3:
  staging uploads, checksum-addressed promotion, MIME and image-dimension
  validation, short-lived signed reads, and local-development CORS support.
- Added Control asset-library, player-character, and NPC/state authoring
  screens, including ready, same-campaign asset validation for portraits and
  avatars.
- Added normalized authored models and guarded Control APIs for NPCs and their
  optional image states; audio music/SFX cues; scenes with primary and
  alternate backdrops, music, transition settings, and base staging; and stage
  presets with normalized NPC/state/position/scale/layer/facing entries and
  tween settings.
- Applied optimistic revisions and idempotent command replay to every new
  authoring mutation, with feature coverage for valid cross-reference paths.
- Added authored maps, initial image-backed fog masks, and PC/NPC/custom token
  layouts; video cues with fallback/completion/music/audio policies; and dice
  presets with campaign defaults.
- Publishing now creates deterministic immutable manifests of every authored
  record and ready asset, and rejects referenced assets that are not ready.

### 7. Revision packages and session foundation — `fb9b859` through `cb52144`

- Added authenticated immutable revision listing and manifest inspection.
- Added ZIP-backed revision export containing package metadata, the immutable
  manifest, and checksum-addressed media.
- Added the first live-session aggregate: a session pins a published revision,
  records fresh/resume intent, issues a player code, and returns a one-time
  display-pairing token while storing only its hash.

## Current architecture

- Backend: Laravel 13, PHP 8.4-compatible, SQLite for isolated tests and
  PostgreSQL/Redis/MinIO in Compose.
- Frontend: Vue 3, Vue Router, Pinia, Vite; Control is currently the exposed
  working SPA. Presentation and Participant entry points are built but are not
  exposed until their authenticated session flows are implemented.
- State integrity: relational campaign data is authoritative; command replay,
  optimistic revisions, audit events, and outbox records protect campaign and
  authored-content mutations.

## Next steps

Implement in this order, committing after each verified section:

1. **Authored content model and asset pipeline**
   - Complete the remaining Control editors and cross-entity publish
     validation reports.
   - Add audio/video duration extraction and asset deletion/archive rules.

2. **Revision adoption and packages**
   - Add live-reference compatibility preflight and explicit revision adoption.
   - Complete ZIP64 content import with archive validation,
     atomic rollback, and round-trip/malicious-package fixtures.

3. **Live-session aggregate and delivery**
   - Complete session lifecycle, progress resume/fresh behavior, participant
     resume tokens, PC claims, named groups, and display pairing.
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
