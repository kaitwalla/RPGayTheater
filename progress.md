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

### 16. Participant live-map surface — `d5b394a`

- Exposed the `/player` Vue entry point with Player/Spectator join and resume
  flows, locally retained resume tokens, and a live current-map view.
- The read-only map supports viewport pan/zoom, renders the selected map image,
  composites the revisioned fog brush history, and renders only the token data
  already authorized for the participant. It reacts to both current-map and
  map-progress realtime topics, with the shared polling fallback.
- Added participant-only signed reads for the selected map image and visible
  token assets; hidden maps and unrevealed token media remain unavailable.

### 17. Control map workspace — `cd8bc1e`

- Added a Control live-session workspace: start a session from a published
  revision, select its pinned map for Players or hide it, apply numeric
  reveal/hide fog brushes, reset authored map state, and save full token
  position snapshots through the revisioned APIs.

### 18. Interactive Control map editor — `102b5b9`

- Added a shared Konva-backed Control map stage using the same normalized
  geometry as participant views. It renders the pinned map image, turns stage
  clicks into reveal/hide brush commands, supports modifier-click additive
  selection and marquee selection, and preserves selected-token offsets during
  group dragging.
- Dragged positions are clamped and committed through the existing complete
  revisioned token snapshot. The JavaScript dependency lockfile is now
  resolved and validated in Linux Docker, avoiding host-native Vite bindings.

### 19. Presentation standby/ready/Go workflow — `d8d580a`

- Added a separate standby presentation state that preserves the currently
  live scene while Control requests preparation. The paired Presentation may
  report only its own session’s pending standby state as ready or error.
- Go is idempotent and revision-protected, and refuses to alter the active
  presentation until the exact standby revision is reported ready. Every
  transition is auditable and delivered through the presentation outbox topic.

### 20. Presentation standby clients — `11b94b3`

- Added Control session controls to select a pinned scene for standby, surface
  preparation status/errors, and enable Go only after the paired display is
  ready.
- Presentation now preloads and decodes the standby backdrop before reporting
  ready, reports a decode failure as an error, and uses display-scoped signed
  asset reads that expose only active or pending standby backdrops.

### 21. Presentation render stage and pairing route — pending commit

- Exposed the paired-display experience at `/presentation`. It accepts the
  one-time session pairing token, then renders the current stage and continues
  to use the existing realtime snapshot/polling fallback.
- Added a display-scoped render endpoint that resolves the pinned scene and
  stage entries into only the backdrop and NPC/state art the display needs.
  Signed reads now allow those active or standby assets, not the campaign's
  complete immutable manifest.
- Added the first shared, responsive Konva Presentation stage: it retains a
  1920×1080 logical canvas, scales to its available 16:9 viewport, draws the
  active backdrop and z-ordered NPCs, and mirrors NPC art when requested
  facing differs from the authored native-facing direction. Active and standby
  media are decoded before a standby Ready acknowledgement.

### 22. Preset-backed scene staging and Control preview — pending commit

- Control now applies the selected pinned scene's base stage-preset entries to
  the standby cue instead of replacing the Presentation stage with an empty
  roster.
- The live-session workspace includes a shared Konva preview of the active
  Presentation cue. It resolves pinned NPC normal/state art, z-order, and
  native-facing in the same shape used by the paired display.

### 23. Shared backdrop transitions — pending commit

- The shared Presentation/Control Konva stage now applies pinned scene cut,
  fade-through-black, and cross-dissolve backdrop transitions using the
  authored duration. It disables animation when the display requests reduced
  motion. NPC preset tweening remains the next stage-motion task.

### 24. Revisioned freeform NPC positioning — pending commit

- The shared stage can now operate in Control edit mode. Dragging a staged NPC
  emits clamped normalized 16:9 coordinates and Control writes the complete
  active presentation cue using its current optimistic revision. A conflicting
  update reloads the authoritative snapshot instead of overwriting it.

### 25. Control stage roster editing — pending commit

- Control can now add a pinned NPC in its normal or selected authored state,
  place it in the foreground by default, and remove an existing staged entry.
  These accessible controls share the same revisioned complete-cue write path
  as freeform drag positioning.

### 26. Preset tweening — pending commit

- Active presentation state now retains the pinned stage-preset ID. Presentation
  resolves its authored tween duration/easing from the immutable manifest and
  interpolates staged NPC positions and scales with linear, ease-in, ease-out,
  or ease-in-out motion.
- Control can explicitly apply a pinned preset to the live stage; that writes
  its complete entry list and selected preset through the revisioned state API.

### 27. Scene-stage reset modes — pending commit

- Control now distinguishes restoring the active scene's authored base-preset
  staging from deliberately clearing the stage. Both actions are revisioned
  complete-cue updates and use the configured preset tween when applicable.

### 28. Alternate scene backdrops — pending commit

- The live Control stage now exposes only the active scene's primary and
  authored alternate backdrops. Switching a backdrop preserves the rest of
  the active cue and uses the same revisioned update/renderer transition path.

### 29. Scene-default presentation music — pending commit

- The display-scoped render contract now resolves the active/standby music cue
  to its signed audio asset and playback settings. Presentation provides an
  explicit audio-unlock control and maintains one active looping scene track,
  stopping it when the scene has no music.

### 30. Full-screen video policies and recovery — pending commit

- Control can author primary/fallback video cues with every configured
  completion, scene-music, and embedded-audio policy, then launch or abort a
  pinned cue from a live session.
- Presentation exposes only the active/standby cue's signed video assets,
  plays the cue full-screen after the launch gesture, applies mute/volume and
  during-video music behavior, tries the fallback, and reports terminal
  playback to the server.
- Video activation captures the underlying complete scene state. Completion
  applies the server-owned policy (including target-scene staging/default
  music); abort and decode failure restore that captured scene. Revision
  adoption also protects captured-state references.

## Current architecture

- Backend: Laravel 13, PHP 8.4-compatible, SQLite for isolated tests and
  PostgreSQL/Redis/MinIO in Compose.
- Frontend: Vue 3, Vue Router, Pinia, Vite; Control, paired Presentation, and
  participant Player entry points are exposed working SPAs.
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
   - Add visible fog compositing and pointer brush strokes to the Control
     stage; current stage clicks create atomic brush operations, and token
     multi-select/group dragging is implemented.
     Realtime subscriptions, reconnect polling, revision-gap recovery,
     degraded-status presentation, transactional outbox dispatch,
     Pusher/Reverb delivery, and the Control delivery-health API are
     implemented.

4. **Presentation and Control live tools**
   - Add the Control WYSIWYG counterpart to the shared presentation stage,
     then complete transitions, scene/reset/backdrop behavior, preset tweening,
     media-engine abstraction, audio, and video policies. Standby/Go, overlay
     lanes, display pairing, and active backdrop/NPC rendering are implemented.

5. **Player/Spectator interactions and maps**
   - Add PWA session flows, role policies, notes/messages/polls/dice, fog,
     read-only participant maps, and Control-only token editing.

6. **Hardening and release**
   - Add OpenAPI generation, passkeys, browser/E2E/accessibility suites,
     load/resilience tests, monitoring, backups/restore rehearsal, and the full
     quality gate defined in `plan.md`.
