# Implementation Progress

Last updated: 2026-07-20

## Latest verification — 2026-07-20

- Hardened the PHP 8.4 quality gate with backend/frontend coverage thresholds,
  semantic mutation testing, formatting/lint/dead-code/duplication checks, and
  deterministic Vite builds.
- Added negative channel-authorization, S3 multipart lifecycle, outbox, dice
  boundary, authored-PC ordering, video/music-policy, and generated API-client
  coverage.
- Added multi-context Playwright checks for isolated Player/Spectator sessions,
  forbidden Spectator claims, one-time Presentation pairing, and simultaneous
  Player claims. The fresh Chromium/Firefox/WebKit/mobile matrix passed 30
  scenarios with no skipped tests, including Chromium virtual-WebAuthn passkey
  registration/login/revocation and fixed-viewport screenshot regression
  fingerprints for Presentation, Control, Android Player, and iOS Player
  shells.
- Added explicit architecture decisions plus operator, Control, Presentation,
  Player, and Spectator workflow documentation.
- Added a requirement-to-implementation/test audit for the repository-owned
  plan scope, with external release evidence called out separately.
- The disposable 30-participant load rehearsal passed all 277 checks with zero
  failed requests and a 51.10 ms ordinary-command p95; the isolated backup and
  restore rehearsal restored both the marker row and object-storage marker.
- The isolated resilience rehearsal passed readiness degradation/recovery for
  PostgreSQL, Redis, MinIO, worker, and Reverb plus pending-outbox retry.
- External hosted-Pusher, real-device audio, and deployed-environment evidence
  remains explicitly pending the required credentials and environment.

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

### 21. Presentation render stage and pairing route

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

### 22. Preset-backed scene staging and Control preview

- Control now applies the selected pinned scene's base stage-preset entries to
  the standby cue instead of replacing the Presentation stage with an empty
  roster.
- The live-session workspace includes a shared Konva preview of the active
  Presentation cue. It resolves pinned NPC normal/state art, z-order, and
  native-facing in the same shape used by the paired display.

### 23. Shared backdrop transitions

- The shared Presentation/Control Konva stage now applies pinned scene cut,
  fade-through-black, and cross-dissolve backdrop transitions using the
  authored duration. It disables animation when the display requests reduced
  motion.

### 24. Revisioned freeform NPC positioning

- The shared stage can now operate in Control edit mode. Dragging a staged NPC
  emits clamped normalized 16:9 coordinates and Control writes the complete
  active presentation cue using its current optimistic revision. A conflicting
  update reloads the authoritative snapshot instead of overwriting it.

### 25. Control stage roster editing

- Control can now add a pinned NPC in its normal or selected authored state,
  place it in the foreground by default, and remove an existing staged entry.
  These accessible controls share the same revisioned complete-cue write path
  as freeform drag positioning.

### 26. Preset tweening

- Active presentation state now retains the pinned stage-preset ID. Presentation
  resolves its authored tween duration/easing from the immutable manifest and
  interpolates staged NPC positions and scales with linear, ease-in, ease-out,
  or ease-in-out motion.
- Control can explicitly apply a pinned preset to the live stage; that writes
  its complete entry list and selected preset through the revisioned state API.

### 27. Scene-stage reset modes

- Control now distinguishes restoring the active scene's authored base-preset
  staging from deliberately clearing the stage. Both actions are revisioned
  complete-cue updates and use the configured preset tween when applicable.

### 28. Alternate scene backdrops

- The live Control stage now exposes only the active scene's primary and
  authored alternate backdrops. Switching a backdrop preserves the rest of
  the active cue and uses the same revisioned update/renderer transition path.

### 29. Scene-default presentation music

- The display-scoped render contract now resolves the active/standby music cue
  to its signed audio asset and playback settings. Presentation provides an
  explicit audio-unlock control and maintains one active looping scene track,
  stopping it when the scene has no music.

### 30. Full-screen video policies and recovery

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

### 31. Revisioned live BGM controls

- Active and standby cues now carry play/pause/stop, seek/restart, loop,
  volume, and fade settings alongside their pinned music cue. Control exposes
  those controls, while Presentation applies them to its sole BGM element.
- Explicit seek commands carry a one-shot position token, so a seek/restart is
  deterministic without resetting the display's real playback position when a
  GM merely pauses, changes volume, or changes looping.

### 32. Revisioned SFX soundboard

- Control can trigger overlapping pinned SFX instances, choose their authored
  loop/default volume, adjust a master volume, stop individual looping sounds,
  or stop every active effect.
- Presentation resolves and plays each instance independently after the audio
  unlock. Completed one-shots report back through the paired-display API so
  stale snapshots cannot replay them after a refresh; only active cue assets
  receive signed presentation reads.

### 33. Participant roster and character claiming

- The Player app now loads the live session's pinned public character roster,
  distinguishes claimed, self-claimed, and available characters, and lets only
  Players claim an available character.
- The server returns roster data from the session revision rather than the
  mutable draft, and serializes an explicit claim-availability check under the
  claim transaction to provide a clear conflict response for competing claims.

### 34. Control participant management

- The live-session workspace now shows joined players and spectators with their
  claim and revocation state.
- Control can release an active player's character claim or revoke a joined
  participant, using the existing audited session APIs and refreshing the
  authoritative roster after each action.

### 35. Explicit live NPC reveals

- Control can reveal or hide only NPCs from the selected session's immutable
  revision; staging an NPC on Presentation has no effect on participant
  discovery.
- Reveals are idempotent, auditable, and delivered through the outbox. Players
  and Spectators receive only currently revealed public NPC profiles.

### 36. Shared NPC note creation

- Revealed NPC profiles now include timestamped, plain-text shared notes with
  their participant author; Spectators can read them.
- Only active Players can add a non-blank note to a currently revealed profile.
  Authors can edit/delete their own notes while the session remains active;
  Control can moderate any note, including archived-session entries. Every
  mutation is idempotent, auditable, and broadcast through the live-session
  outbox.

### 37. Session-scoped Player group management

- Control can create uniquely named Player groups for a selected live session,
  inspect their memberships, and add or remove only active Player
  participants. Groups and memberships cannot cross session boundaries.
- Every group mutation is command-idempotent and emits a session event plus an
  outbox notification. Players see only their own group names; Spectators see
  none.
- Starting a resumed session defaults to copying the immediately previous
  session's group names (and can be toggled off); fresh sessions default off.
  A copied membership is restored only after a Player claims the same PC.

### 38. Recipient-snapshotted session conversations

- Control can send plain-text messages to an individual participant, a named
  Player group, All Players, All Spectators, or everyone. Recipient snapshots
  prevent later joins or membership changes from exposing earlier messages.
- Players can message Control or their own groups; Spectators can message
  Control only. Broadcast replies are private to Control, and no interplayer
  direct-message target exists.
- Both live apps provide message history/composers. Mutations are throttled,
  idempotent, audited, and dispatched as lightweight realtime hints.

### 39. Recipient-snapshotted live polls

- Control can create a multiple- or single-choice poll for an individual
  participant, named Player group, All Players, All Spectators, or everyone.
  The audience is snapshotted when the poll opens, so later joins and group
  changes cannot gain access to it.
- Recipients may replace an open vote. Control can close the poll, publish
  anonymous aggregates live, and publish final results only after it closes.
  Participant responses never expose another voter's identity.
- Both live apps expose the poll workflow; creation, votes, closing, and
  result publication are command-idempotent, audited, and sent through the
  transactional outbox.

### 40. Server-evaluated session dice

- Control-authored and ad-hoc Player expressions support integers, NdM,
  addition/subtraction, parentheses, and keep-high/low terms. Unsupported
  dice syntax is rejected; evaluation uses cryptographically secure randomness
  and stores individual dice with their kept/dropped status.
- Players can choose public or private rolls, use the dice presets pinned to
  their live-session revision, and see the public feed plus their own private
  results. Spectators cannot roll. Control can inspect every result and reveal
  a private roll later.
- Public rolls and newly revealed private rolls append a source-linked,
  unpinned eight-second corner overlay through the existing revisioned queue.
  Roll mutations are throttled, idempotent, audited, and delivered through the
  transactional outbox.

### 41. Named published Spectator replies

- Control can publish a private Spectator-to-Control reply to Presentation.
  The full overlay includes the Spectator's entered display name, is source
  linked to its session message, queues behind an active full-lane entry, and
  defaults to 15 unpinned seconds.
- Publication is limited to active sessions and private Spectator replies;
  Player messages are rejected. It is command-idempotent, audited, and sent
  through the existing overlay-state outbox topic.
- The Control message history exposes the action only for qualifying
  Spectator replies.

### 42. Participant PWA shell and offline safety

- The Player/Spectator route now exposes an installable web manifest, a
  same-origin module service worker, and a neutral maskable application icon.
- Its cache policy is deliberately narrow: it caches only the `/player`
  application shell and versioned Vite build assets. Authenticated APIs,
  signed/private media, URLs with query strings, and non-Player pages are
  never cached.
- The participant UI reports an explicit offline state, disables all controls
  while offline, and reconnects/refetches authoritative session state when
  connectivity returns.

### 43. Media metadata and safe asset archiving

- Audio and video uploads are now decoded with `ffprobe`; successful assets
  retain a normalized duration in their metadata alongside image dimensions.
  The application and quality images include the required media probe.
- Control can archive only completed, unreferenced assets. The reference audit
  protects every mutable authored relation and every immutable revision
  manifest, preserving media required by published/live content.
- Archived assets remain readable for already-pinned content but cannot be
  selected in new authoring or included in future manifests. Archival is
  revisioned, command-idempotent, audited, and sent through the outbox.

### 44. Browser security headers

- All HTTP responses now have a CSP that limits executable and embedded
  content while allowing the same-origin SPAs, signed media, and realtime
  connections. Local development receives only the Vite/Reverb allowances it
  needs; production receives HSTS.
- Added clickjacking, MIME-sniffing, referrer, cross-origin opener, and
  permissions-policy protections. Production session cookies default to
  `Secure` unless explicitly configured otherwise.

### 45. OpenAPI contract and generated client

- Added a hand-authored OpenAPI 3.1 contract for the Control authentication
  route family, including its success, validation, and error responses.
- `openapi-typescript` now generates the shared API types and `openapi-fetch`
  powers the typed Control secret-login request. The quality gate regenerates
  to a temporary file and compares it with the checked-in output, preventing
  client contract drift.
- Added a feature-level contract sample that validates the documented Control
  authentication responses against the running application.

### 46. Control stage-preset editor

- Added the missing Control draft route for stage presets, linked from each
  campaign alongside the other authored-content editors.
- Control can now create named tweened presets and add any number of NPC
  placements with a normal or state image, normalized position, scale, layer,
  and facing. The editor consumes the existing revisioned, idempotent API and
  displays the resolved placement details for the selected preset.

### 47. Control map-authoring editor

- Added the missing Control draft map route, linked from each campaign with
  the other authored-content editors.
- Control can create a map from a ready private image, add or replace its
  initial fog mask, and lay out any number of PC, NPC, or labeled custom-image
  tokens with normalized positions and scale. The editor only offers valid
  same-campaign authoring references and uses the existing revisioned APIs.

### 48. Draft publish preflight and Control publish flow

- Added a read-only publish-preflight endpoint that runs the same manifest
  validation as publishing and returns a human-readable issue or a concise
  authored-content inventory without mutating the draft.
- Control now requires this preflight immediately before publishing an
  immutable revision, shows any blocking issue, and confirms successful
  revision publication. Feature coverage proves both ready and invalid draft
  reports match the publish validator.

### 49. Control revision package workflow

- Control now lists a campaign's immutable revision history and exports any
  selected revision as a verified ZIP package.
- Added package import to the campaign dashboard. Imported packages become
  new editable drafts with remapped private media, using the same multipart
  CSRF and API-error handling as other Control commands.

### 50. Control live-session revision adoption

- Added a live-session revision-adoption workspace that preflights a selected
  published revision before making any change.
- Control now sees compatible/incompatible status, explicit blockers, and
  per-collection added/removed/changed counts. Adoption requires an explicit
  confirmation and refreshes the authoritative pinned workspace afterward.

### 51. Control passkey authentication and recovery

- Control now uses a dedicated authenticated user backed by Laravel Fortify and
  the first-party WebAuthn passkey implementation. The environment-held Control
  secret remains the explicit recovery method and bootstraps that identity.
- The Control login page supports passkey sign-in. Its new security workspace
  lists labelled credentials, supports labelled registration and revocation,
  and requires a fresh environment-secret confirmation before either change.
- Realtime private-channel authorization now resolves the Control principal
  from the authenticated guard, so secret and passkey sessions share the same
  channel permissions. The generated OpenAPI auth contract also documents the
  secret-confirmation route, and the frontend lockfile is reproducible in the
  Linux build environment.

### 52. Control live fog painting and component-test baseline

- The Control map stage now composites the authoritative live fog state over
  the map, including existing reveal/hide brush operations, while retaining
  its token editor.
- Map editing now has explicit token and fog-painting modes. Pointer strokes
  are sampled into ordered, revision-aware brush commands so long drags do not
  flood the session API with every browser event.
- Added a Vitest/Vue Testing Library baseline with focused tests for stroke
  sampling and the fog editor's accessible mode; the existing PWA policy tests
  continue to run in the same frontend test command.

### 53. Unified frontend quality gate

- The repository's single `composer quality` command now runs the frontend
  Vitest/Vue and PWA test command in addition to formatting, static analysis,
  backend tests, generated-contract validation, and the production build.
- Updated the quality-gate documentation so its stated coverage matches the
  executable command used by the containerized release check.

### 54. Participant API OpenAPI contract

- Documented every participant-session route in the OpenAPI 3.1 source:
  joining/resuming, roster and groups, messages, polls, rolls, claims, NPC
  notes, and participant-safe map reads.
- The generated TypeScript client declarations now expose the complete
  participant family, including bounded mutation requests, idempotency metadata,
  authorization outcomes, fog-filtered map progress, and signed asset reads.
- Added a contract-coverage test that locks the document to all 16 participant
  path templates / 19 operations and verifies the sensitive resume, messaging,
  and poll-vote constraints.

### 55. Presentation API OpenAPI contract

- Documented the entire paired Presentation API: pairing, authoritative state
  and resolved render reads, signed asset access, overlays, and revision-aware
  standby/video/SFX reports.
- The generated declarations model display-report idempotency and the explicit
  stale-state conflict snapshot, so Presentation clients can recover from a
  revision race without treating a conflict as an opaque error.
- Added a contract-coverage test for all nine Presentation operations and their
  pairing-token, revision, and stale-conflict invariants.

### 56. Control campaign lifecycle OpenAPI contract

- Documented Control campaign drafts and immutable revisions: campaign list,
  create/rename/archive, read-only publish preflight, publishing, revision
  history/detail, verified ZIP export, and multipart package import.
- The generated contract now distinguishes mutable campaign responses from
  immutable revision responses, records replay metadata, and exposes the
  authoritative campaign in stale-revision conflicts.
- Added contract coverage for all ten lifecycle operations, including multipart
  import, ZIP download, revision bounds, and stale-draft recovery.

### 57. Control asset pipeline OpenAPI contract

- Documented the entire private campaign-asset lifecycle: listing, multipart
  initiation/completion, short-lived signed reads, and reference-safe archival.
- Generated types now capture media-kind validation, multipart part bounds and
  upload URLs, upload validation state, and revision-conflict recovery.
- Added exact route coverage for all five asset operations and their sensitive
  kind, part-limit, signed-read, and stale-revision constraints.

### 58. Control character and NPC API OpenAPI contract

- Documented Control's player-character, NPC, and NPC appearance-state list
  and creation operations, including revision-aware idempotent mutations.
- Generated shared declarations now model optional player avatars, required NPC
  normal assets, authored-state assets, public descriptions, and native
  left/right facing.
- Added contract coverage for all six operations, the public-description bound,
  native-facing choices, and stale-revision recovery.

### 59. Control media and stage authoring API OpenAPI contract

- Documented the seven remaining presentation-content routes: audio and video
  cues, dice presets, scenes and alternate backdrops, plus stage presets and
  their NPC entries.
- Generated shared declarations now preserve asset/cross-entity references,
  media completion and music policies, scene transitions, and bounded stage
  geometry and tween settings.
- Added exact coverage for all 14 operations and their sensitive enum, timing,
  positioning, and stale-revision constraints.

### 60. Control map authoring API OpenAPI contract

- Documented map list/create, authored fog-mask read/write, and authored token
  list/create operations for Control.
- Generated declarations capture nullable initial fog masks, revision-aware
  image references, and the PC/NPC/custom token source model with bounded map
  geometry.
- Added exact route coverage for all six operations plus fog nullability,
  token-source choices, geometry bounds, and stale-revision recovery.

### 61. Control live-session lifecycle API OpenAPI contract

- Documented Control's live-session list/create and safe published-revision
  adoption workflow, including the read-only compatibility preflight.
- Generated declarations now model progress and group-copy choices, the
  one-time display pairing credential, session status, and the structured
  adoption blockers/change report.
- Added exact coverage for all four lifecycle operations and their progress,
  session-status, credential, and adoption-response invariants.

### 62. Control session presentation-state API OpenAPI contract

- Documented authoritative presentation-state reads, revision-aware updates,
  standby preparation, and Go activation for a paired live session.
- Generated declarations cover the composed scene/music/SFX/video/stage target,
  including media and stage bounds plus the dedicated stale-state snapshot.
- Added exact coverage for all four operations and their SFX, fade, facing, and
  stale-presentation invariants.

### 63. Control session overlay API OpenAPI contract

- Documented the independent Control overlay aggregate: snapshot, enqueue,
  entry edit, and corner/full lane advance or dismissal.
- Generated declarations now model queued overlay entries, optional provenance,
  bounded display content and duration, and the dedicated stale-overlay
  snapshot used for recovery.
- Added exact coverage for all five operations, lane choices, entry bounds, and
  overlay-revision conflict handling.

### 64. Control live-map API OpenAPI contract

- Documented participant map selection/hiding and pinned-map progress reads,
  token placement updates, resets, and reveal/hide fog brushes.
- Generated declarations now model the participant-visible map state, seeded
  token progress, fog masks and bounded brush histories, plus their independent
  revision conflict snapshots.
- Added exact coverage for all six operations, fog brush bounds/history, and
  distinct player-map and map-progress stale recovery responses.

### 65. Control session participants and groups API OpenAPI contract

- Documented participant roster moderation, claim release, and session-scoped
  access revocation.
- Added the named Player-group list/create and active-Player membership
  operations, including command replay metadata and explicit no-content
  moderation results.
- Generated declarations now carry claimed-character and revocation state,
  bounded group names, and UUID-scoped group membership; exact coverage
  verifies all seven operations and their contract invariants.

### 66. Control session collaboration API OpenAPI contract

- Documented Control's session-wide message history and sends, including the
  one-way publication of a private Spectator reply to the presentation overlay.
- Added poll creation, recipient audience choices, close/result-publication
  lifecycle, and authoritative vote counts, alongside private-roll listing and
  public reveal.
- Generated declarations now keep moderation and collaboration targets,
  bounded message/poll input, poll-state/result visibility, and roll disclosure
  separate from participant-safe models; exact coverage verifies all nine
  operations and their key bounds and enums.

### 67. Control session NPC disclosure API OpenAPI contract

- Documented explicit NPC profile reveal/hide state for a pinned live-session
  revision, including its independent reveal timestamp.
- Added the complete Control note-moderation history and edit/delete commands,
  which retain authorship and remain available for archived sessions.
- Generated declarations distinguish Control moderation note records from
  participant-safe profile projections; exact coverage verifies all five
  operations, disclosure state, note bounds, and author choices.

### 68. Control operational API OpenAPI contract

- Documented authenticated passkey inventory and transactional-outbox delivery
  health, including pending/failed counts and nullable latest-attempt details.
- Generated declarations now model passkey-use history and realtime delivery
  failures without exposing credential material.
- Added exact coverage for both remaining app-owned Control operations and
  their nullable timestamp/error and non-negative-count invariants.

### 69. Release quality automation and deployment runbook

- Added a GitHub Actions quality workflow that runs the same containerized,
  single-command gate used locally on pull requests and `main` updates.
- Added an executable source-integrity test that rejects provisional delivery
  markers from application, route, and frontend sources.
- Documented production configuration, release verification, rollback
  boundaries, and the release evidence required alongside the existing backup
  and restore rehearsal instructions.

### 70. Browser and accessibility verification foundation

- Added isolated Playwright coverage for the unauthenticated Control, Player,
  and Presentation shells in Chromium, Firefox, and WebKit.
- Each browser check confirms the page landmark, application heading, and an
  axe scan scoped to the rendered application content.
- Added a disposable Compose browser runner and CI job that migrates its stack
  before executing the cross-browser accessibility suite.
- The suite exposed and corrected production builds using Vue's runtime-only
  bundle, missing Laravel view-cache directories, and a leaked Vite hot-reload
  marker; local Compose now also supplies an explicitly non-production app key.
- Corrected unauthenticated Presentation null-state rendering and Player map
  landmark/keyboard semantics before enabling axe across all three engines.

### 71. Observability runtime foundation

- Added a dependency-free `/live` probe alongside `/ready`, so process liveness
  is distinct from database, cache, and object-storage readiness.
- Added request IDs to every response and structured JSON logs, accepting only
  safe caller-provided correlation IDs and generating UUIDv7 values otherwise.
- Documented probe semantics and request-ID correlation for local operators and
  production release evidence.

### 72. Isolated backup and restore rehearsal

- Added a disposable procedure that backs up PostgreSQL and MinIO object data
  from one no-host-port Compose project and restores them into another.
- The rehearsal proves both a database record and private object survive the
  restore, reruns migrations, validates `/ready`, then removes every test-only
  volume and temporary archive.
- Added the rehearsal to CI while keeping it explicitly separate from the
  encrypted, operator-managed production backup process.
- Verified on 2026-07-20: the database record and private-object marker
  `restore-rehearsal-20260720T192522Z` restored into the separate target
  project; migrations were current and `/ready` reported every dependency ok.

### 73. Authenticated browser regression coverage

- Extended the cross-browser suite with the real Control secret login and
  logout flow, covering session-cookie protected navigation in all three
  browser engines.
- Corrected OpenAPI fetch handling to preserve request details, and moved SPA
  writes to Laravel's rotating XSRF cookie so a regenerated session cannot
  leave a stale CSRF token behind.
- The disposable browser stack now uses a dotted private network alias, which
  lets WebKit accept session cookies without opening a host port.

### 74. 30-participant load gate

- Added an isolated k6 workload with one Control, one Presentation, and 30
  simultaneous Player sessions covering joins, poll voting, message bursts,
  public rolls, a fog stroke, and resume-token reconnects.
- The load runner provisions a deterministic non-production fixture and fails
  on any failed request or an ordinary API-command p95 at or above 250 ms.
- Updated the MinIO readiness probe to use the utility supplied by the current
  MinIO image, preventing its floating image tag from blocking Compose startup.
- The application image now starts PHP's built-in server with eight workers by
  default so concurrent session traffic does not serialize behind one process.
- Verified on 2026-07-20: the isolated rehearsal completed all 277 checks with
  zero failed requests; ordinary-command p95 was 58.89 ms (below the 250 ms
  gate) across 30 Player VUs plus Control and Presentation activity.

### 75. Service-interruption resilience rehearsal

- Extended `/ready` to report the Redis-backed realtime queue independently of
  cache availability, with feature coverage for every unavailable dependency.
- Added a disposable, no-host-port Compose rehearsal that independently stops
  PostgreSQL, Redis, MinIO, the worker, and Reverb; it verifies explicit
  readiness or outbox failure/pending states, then verifies recovery without
  losing queued realtime events.
- Added the rehearsal to CI and documented its production-operator scope.
- Verified on 2026-07-20: the isolated rehearsal independently degraded and
  recovered PostgreSQL, Redis, MinIO, the worker, and Reverb; both queued and
  failed realtime outbox events converged to dispatched without data loss.

### 76. Hosted Pusher staging smoke test

- Added a staging-only, Pusher-only command that publishes one ephemeral,
  private-channel probe without persisting domain, audit, or outbox state.
- Added feature coverage for successful publication and guards that reject
  non-staging or Reverb-backed invocations.
- Documented the required Pusher configuration and release-evidence procedure
  while keeping normal CI deterministic through local Reverb.

### 77. Frontend realtime fallback regression coverage

- Added fake-clock coverage for the no-socket polling fallback, refresh
  failures, recovery through the next snapshot, and cleanup on unmount.
- Added a mocked realtime-client regression that proves revision gaps trigger
  both the caller recovery hook and an authoritative snapshot reload.
- Added socket-state coverage showing a disconnect enables two-second polling
  and a subsequent reconnect returns to live mode and cancels that fallback.

### 78. Map interaction geometry regression coverage

- Added frontend coverage for 16:9 coordinate normalization, out-of-bounds
  clamping, selected-token translation, and edge clamping during group moves.

### 79. Player PWA registration lifecycle coverage

- Added browser-environment regression coverage for secure Player service-worker
  registration after load and the intentional no-op behavior on insecure or
  unsupported browsers.

### 80. Shared browser API client regression coverage

- Added frontend coverage for same-origin JSON requests, decoded CSRF headers,
  multipart upload safety, and status-aware API errors used by all application
  shells.

### 81. Presentation stage rendering regression coverage

- Added component coverage for preloaded stage assets, 16:9 placement, z-order,
  native-facing flips, scale calculation, and bounded normalized coordinates
  emitted from editable stage drags.

### 82. Mobile browser compatibility gate

- Expanded the Playwright browser matrix with representative Android Chrome and
  iOS Safari device profiles alongside the existing desktop engines.
- Added a real authenticated Control campaign-creation workflow to that matrix.

### 83. Insecure-context command-id resilience

- Added a standards-compliant UUID fallback for browser command IDs when
  `crypto.randomUUID()` is unavailable, uncovered by the HTTP browser gate.
- Applied the fallback consistently to Participant and Presentation mutations.

### 84. Player PWA update lifecycle coverage

- Added service-worker event coverage for shell precaching, immediate worker
  activation, stale Player-cache cleanup, and claiming already-open pages.

### 85. Control map group-drag interaction coverage

- Added component-level coverage for multi-select token dragging, normalized
  group translation, and disabled-map interaction suppression.

### 86. Player read-only map interaction coverage

- Added component-level coverage for Player map accessibility, visible-token
  rendering, fog compositing, image loading, and labelled zoom controls.

### 87. Presentation stage transition and tween coverage

- Added deterministic animation-frame coverage for fade-to-black backdrop
  transitions and timed interpolation of stage-preset position and scale.

### 88. Control fog pointer-stroke coverage

- Added component-level coverage for Control fog pointer events, ensuring
  crowded samples are coalesced while first and final brush positions remain.

### 89. Frontend TypeScript release gate

- Added a checked `vue-tsc --noEmit` frontend gate to the containerized quality
  command and resolved the first concrete async-handler and realtime-global
  type errors it exposed.

### 90. Keyboard-accessible Control map editing

- The interactive Control map token editor is now focusable, announces its
  keyboard controls, and reports the currently focused token through a polite
  status message. Arrow keys choose a token, Space or Enter toggles its
  selection, and Alt+Arrow nudges selected tokens (with Shift for a larger
  step); existing numeric position controls remain available as an alternative.
- Added component coverage for keyboard selection and normalized nudge output,
  including the accessible focus and instructions.

### 91. Playwright security update

- Updated the direct Playwright test dependency and matching browser-runner
  image from 1.54.1 to 1.55.1, resolving the high-severity certificate
  validation advisory reported by `npm audit`.
- The regenerated pinned Node 24 lockfile reports zero audit vulnerabilities,
  and the complete containerized quality gate passed. The matching Playwright
  1.55.1 runner completed all 20 disposable cross-browser/axe checks in 22.8
  seconds after the stale crash-era test stack was removed.

### 92. Dependency-audit quality gate

- The single Composer quality command now explicitly runs Composer's advisory
  audit and npm's high-severity dependency audit before frontend tests/builds.
- Both the patched npm lockfile and Composer's dependency audit report no
  security advisories; the augmented containerized quality command passed.

## Current architecture

- Backend: Laravel 13, PHP 8.4-compatible, SQLite for isolated tests and
  PostgreSQL/Redis/MinIO in Compose.
- Frontend: Vue 3, Vue Router, Pinia, Vite; Control, paired Presentation, and
  participant Player entry points are exposed working SPAs.
- State integrity: relational campaign data is authoritative; command replay,
  optimistic revisions, audit events, and outbox records protect campaign and
  authored-content mutations.

## Remaining release evidence

All repository-owned implementation milestones in `plan.md` are now present:
the authoring and package pipeline, revision adoption, live-session delivery,
WYSIWYG Presentation/Control tooling, Player PWA/maps, and local hardening
gates. The remaining steps require deployment authority or real hosted
credentials rather than another source change:

1. Run `php artisan realtime:smoke-pusher` in a configured staging environment
   and retain its output as hosted-provider evidence.
2. For a release candidate, retain the verified Playwright 1.55.1
   cross-browser result plus the 30-participant load, interruption-resilience,
   and backup/restore rehearsals alongside the final quality-gate result.
3. Perform the deployment-runbook’s production configuration and rollback
   checks with the production secrets and infrastructure owner.
