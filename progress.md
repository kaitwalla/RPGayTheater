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

### 7. Revision packages and session foundation — `fb9b859` through `5929a15`

- Added authenticated immutable revision listing and manifest inspection.
- Added ZIP-backed revision export containing package metadata, the immutable
  manifest, and checksum-addressed media.
- Added authenticated, idempotent ZIP import into a new campaign draft. Imports
  validate schema/hash/media entries and byte limits before mutation, remap the
  complete authored graph and assets, clean up failed media writes, and support
  shared media checksums. Full authored-graph round-trip and invalid-archive
  fixtures cover the contract.
- Added the first live-session aggregate: a session pins a published revision,
  records fresh/resume intent, issues a player code, and returns a one-time
  display-pairing token while storing only its hash.

### 8. Session access lifecycle — `12e3327` through `0a15e82`

- Added one-time Presentation pairing: pairing exchanges only the high-entropy
  token for a rotated display credential and activates the session.
- Added case-insensitive Player/Spectator joins, hashed resume tokens, session
  resume exchange, and revision-pinned PC claims guarded by database
  uniqueness constraints.
- Added authenticated Control participant administration: list attendees,
  release a claim, or revoke a participant (which also releases their claim).

### 9. Revision adoption — `ee38aa5`

- Added Control preflight between a live session's pinned revision and any
  same-campaign published revision. It reports added, removed, and changed
  authored records across scenes, stages, NPCs, maps, assets, and media.
- Added explicit, idempotent adoption with audit/outbox records. Adoption
  rejects any revision that removes a Player character already claimed in the
  live session; this is the currently persisted live reference.

### 10. Presentation runtime snapshot — `952b169`

- Added a one-per-session, revisioned presentation-state aggregate with
  idempotent Control updates, stale-write snapshots, append-only event/outbox
  records, and paired-display reads.
- The state validates scene, backdrop, music, video, and staged NPC/state
  references against the session's pinned revision. Revision adoption now also
  rejects a target that would remove an active presentation reference.

### 11. Map runtime progress — `945cc6f`

- Added lazily initialized, per-session/per-map revisioned snapshots seeded
  from the immutable pinned map, fog-mask, and token manifest records.
- Added authenticated Control reads, complete-token movement writes, reset to
  authored defaults, idempotent command replay, stale-write snapshots, and
  append-only session-event/outbox records.
- Revision adoption now rejects a target revision that removes an active map,
  its active fog-mask asset, or a token retained in map progress.

### 12. Overlay runtime lanes — `e0f25c4`

- Added a one-per-session, revisioned overlay snapshot with independent corner
  and full lanes. Each lane has a current item and FIFO queue, so the two
  placements can display simultaneously.
- Added authenticated Control operations to enqueue, edit content/duration/pin,
  move an item between lanes, advance, and dismiss. Each mutation is
  idempotent, stale-write protected, and recorded through session events and
  transactional outbox rows.
- Added paired-Presentation reads. Overlay entries retain source metadata for
  future roll, reply, and poll producers without coupling this aggregate to
  those features.

### 13. Transactional realtime delivery — `d8b0fc3`

- Added an after-commit outbox observer and high-priority queued dispatcher.
  Delivery uses a persisted lease, increments attempts, preserves failure
  diagnostics, and marks a row dispatched only after the publisher succeeds.
- Added scheduled recovery for pending rows, a Control delivery-health API, and
  a 9 KiB payload ceiling to stay below Pusher’s event-size limit.
- Added Pusher-compatible private-channel publishing, session-backed broadcast
  principals and channel authorization, plus a local Laravel Reverb service
  and Redis-backed worker topology in Compose.
- Added a shared Echo/Reverb client controller for Control and Presentation.
  It treats events as invalidation hints, refetches on revision gaps and
  reconnect, polls authoritative snapshots every two seconds while degraded,
  and exposes connection status in the UI.

### 14. Live fog and participant map snapshots — `dcf43a2`

- Added ordered, revisioned reveal/hide brush operations over each map's
  authored fog baseline. Brush commands are validated, idempotent,
  stale-write protected, audited, and published through the existing map
  progress outbox topic.
- Fog-masked maps start hidden until revealed; maps without an authored fog
  mask start revealed. A participant snapshot applies the brush history to
  token positions and omits every unrevealed token record, including its
  identity and label.
- Added participant-authenticated, read-only map-progress reads scoped to the
  participant's own live session. Revoked participants are refused.

### 15. Current Player-map selection — `34a37d2`

- Added a one-per-session current Player-map state. Control can select a
  pinned-revision map or explicitly hide it, using idempotent, revisioned
  commands with stale-write snapshots, session events, and outbox delivery.
- Participant map reads now expose only the selected map; hiding it returns an
  explicit empty current-map snapshot and rejects direct reads for the hidden
  map. The selection has its own participant-authorized realtime topic.
- Revision-adoption preflight now blocks removal of the currently selected
  Player map.

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
   - Extend preflight preservation checks as presentation/map runtime snapshots
     are added; current claim preservation and explicit adoption are complete.

3. **Live-session aggregate and delivery**
   - Complete progress resume/fresh behavior and named groups/optional
     transfer; session identity, pairing, participant resume tokens, claims,
     and Control revocation are implemented.
   - Wire the existing participant map snapshot and fog commands into the
     Participant and Control clients.
     Realtime subscriptions, reconnect polling, revision-gap recovery,
     degraded-status presentation, transactional outbox dispatch,
     Pusher/Reverb delivery, and the Control delivery-health API are
     implemented.

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
