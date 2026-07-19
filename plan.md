A previous agent produced the plan below to accomplish the user's task. Implement the plan in a fresh context.
  Treat the plan as the source of user intent, re-read files as needed, and carry the work through implementation
  and verification.

  # Theatrical RPG Orchestration Platform

  ## 1. Summary and foundation

  Build a theatrical presentation-and-participation system, explicitly not a VTT: no character sheets, combat
  engine, tactical movement, initiative, or player-controlled tokens.

  - Use [Laravel 13](https://laravel.com/docs/13.x/releases), PHP 8.4, PostgreSQL, Redis, S3-compatible object
  storage, and Pusher Channels.
  - Create three Vue 3/TypeScript/Vite SPA entry points with shared types and rendering modules:
    - Control/Admin: authenticated desktop interface.
    - Presentation: fixed 1920×1080 logical stage, scaled and letterboxed.
    - Player: mobile-first installable PWA; cache the application shell only and show an explicit reconnect state
  when offline.
  - Use Vue Router, Pinia, Tailwind CSS, Konva/vue-konva for the shared stage/map renderer, and native Web Audio/
  HTML media behind a testable media-engine interface. Vue’s current TypeScript/Vite approach is documented [here]
  (https://vuejs.org/guide/typescript/overview).
  - Production realtime uses Pusher; local development and deterministic E2E tests use [Laravel Reverb’s Pusher-
  compatible protocol](https://laravel.com/docs/13.x/reverb). Realtime accelerates delivery but never owns state.
  - Target one live session per campaign and 30 concurrent participants, plus Control and Presentation.
  - Support the latest two Chrome, Edge, Firefox, and Safari majors, plus iOS Safari and Android Chrome. Optimize
  Presentation for desktop Chromium.
  - Provide containerized local services, queue workers, scheduler, health checks, structured logs, database/object-
  storage backup instructions, and CI from the first implementation phase.
  - Create no artwork. Production empty states use neutral CSS labels/blocks; automated tests use minimal technical
  media fixtures only.

  ## 2. Product behavior

  ### Admin and reusable content

  - Admin manages multiple campaigns and a private asset library:
    - Still images for backdrops, maps, PC avatars, NPC portraits, and NPC emotional states.
    - Music and sound-effect audio.
    - MP4 H.264/AAC full-screen video cues, with optional WebM alternatives.
  - A campaign draft contains PCs, NPCs and states, scenes, alternate backdrops, stage presets, maps, initial fog
  masks, map tokens, music/SFX definitions, video cues, dice presets, and defaults.
  - PCs contain character name, avatar, pronouns, and short public description. The current participant’s entered
  name becomes the smaller player-name label.
  - NPCs contain public profile information, a required normal image, optional emotion/state images, and native-
  facing metadata so Control’s Left/Right commands mirror correctly.
  - Scenes contain:
    - Primary and alternate backdrops.
    - Default music, which always restarts from the beginning on scene entry.
    - An optional base NPC staging preset; otherwise the base stage is empty.
    - Default transition style and timing.
  - Stage presets store the complete NPC roster, state, normalized position, scale, layer order, facing, and tween
  duration/easing.
  - Maps contain an image, admin-authored initial fog mask, and initial PC/NPC/custom token layout.
  - Admin edits a mutable draft. Publishing performs complete validation and creates an immutable campaign revision
  and asset manifest.
  - A live session remains pinned to its revision. When another revision is published, Control runs a compatibility
  preflight and explicitly adopts it. Existing live media remains intact; newly loaded cues use the new revision.
  Immutable referenced assets remain available until no revision/runtime state needs them.
  - Export a selected published revision as a content-only ZIP64 package with schema version, metadata, manifest,
  checksums, and media. Include PCs and authored content; exclude participants, player names, messages, rolls,
  polls, notes, discoveries, fog progress, passkeys, and session history.
  - Import validates the entire archive before mutation and creates a new campaign draft; v1 does not merge into an
  existing campaign or execute package code.

  ### Live session and Control

  - Session creation selects a published revision and either:
    - Resumes the latest campaign progress; or
    - Starts from authored map/reveal/note defaults.
  - Progress includes NPC reveals/notes and map fog/token positions. Presentation scene/audio/overlays, character
  claims, conversations, polls, and rolls do not carry automatically.
  - Named Player groups remain session-scoped. Session creation may copy names and PC-based memberships from the
  immediately previous session without copying messages. Default this on for resumed progress and off for fresh
  progress.
  - Generate a rotatable human-friendly Player session code and a separate unguessable Presentation pairing link.
  - Control shows participant/display connectivity, current revisions, asset readiness, failed cues, queue failures,
  and degraded-realtime status.
  - Scenes and video use a Standby/Go workflow:
    - Standby asks Presentation to preload/decode the cue and reports Ready/Error.
    - Go is enabled when ready.
    - Quick Go is allowed only for a cue the active Presentation has already reported ready.
    - A media failure before Go leaves the current presentation untouched.
  - Scene entry applies the full authored base: cancel video, primary backdrop, default music from zero, and
  optional base NPC preset.
  - Reset Scene offers:
    - Full authored base, the default.
    - Backdrop/music reset while preserving current NPC staging.
  - Switching an alternate backdrop preserves NPC staging and music.
  - Control’s stage preview is WYSIWYG with Presentation. It supports freeform NPC placement, state, scale, z-order,
  facing, manual removal, and applying tweened presets. There is no fixed NPC-count schema limit.
  - Provide cut, fade-through-black, and cross-dissolve transitions plus configurable NPC tween duration/easing.
  - Full-screen video hides but preserves the underlying scene. Each cue independently configures:
    - Completion: restore the captured scene or enter a selected target scene.
    - During-video music: continue, pause, or stop.
    - After-video music: keep current state, resume prior music, start the target scene default, or remain silent.
    - Embedded-video audio volume/mute.
    - Complete runs the authored completion policy; Abort restores the captured scene/music.
  - Background-music soundboard provides play, pause, restart, stop, seek, loop, fade, and volume. Only one BGM
  track is active.
  - SFX soundboard supports overlapping one-shots, optional looping cues, per-cue/master volume, stopping individual
  loops, and Stop All.
  - All sound is produced by Presentation so Discord screen sharing captures it. Presentation requires one launch
  gesture to unlock audio/fullscreen.

  ### Maps, interactions, and overlays

  - Control selects or hides the current Player map and sees the full map with translucent fog.
  - Fog supports live reveal/hide brushes. Tokens beneath unrevealed fog are hidden from participants.
  - Only Control moves tokens. Support modifier-click multi-select, marquee selection, group dragging while
  preserving offsets, keyboard-accessible selection, and numeric/nudge alternatives.
  - Reset Map restores the admin-defined initial fog and token layout after confirmation.
  - Player/Spectator map views are read-only with pan/zoom and no grid math, measurements, pathfinding, or movement
  rules.
  - Control explicitly reveals/hides NPC profiles; appearing on Presentation never reveals one automatically.
  - Revealed NPCs have timestamped plain-text shared note entries:
    - Players may add entries.
    - Spectators may read them.
    - Authors may edit/delete their own entries during the originating live session.
    - Control may moderate any entry; archived-session entries become Control-editable only.
  - Audience targets are Individual, Named Player Group, All Players, All Spectators, and All.
  - Named Player groups are two-way chats visible to members and Control. Broad sends are broadcasts whose replies
  return privately to Control.
  - Players may message Control and their named groups only. Spectators may message/reply privately to Control.
  There are no interplayer direct-message endpoints.
  - Messages and notes are plain text without Markdown or attachments.
  - Polls may target any audience target. Support single- or multiple-choice responses, recipient snapshots,
  response changes until close, live Control results, and explicit close.
  - Control may publish live or final aggregate poll results without voter identities.
  - Control may publish selected Spectator replies to Presentation; published replies always include the entered
  Spectator name.
  - Presentation has independent overlay lanes:
    - Corner: lower-right, approximately one-fifth of the frame; default for rolls.
    - Full: lower third; default for replies and poll prompts/results.
    - Each lane has its own queue and may display simultaneously. Control can override placement, duration, pin,
  advance, and dismiss.
  - Player dice expressions support integer/NdM terms, `+`, `-`, parentheses, and `khN`/`klN`, such as `4d6kh3+2`.
  Reject explosions, rerolls, comparisons, and success-counting syntax.
  - Evaluate rolls server-side with cryptographically secure randomness and persist individual, kept, and dropped
  dice. Apply configurable abuse limits, defaulting to 200 expression characters, 100 total dice, and 2–1000 sides.
  - Public rolls enter the participant feed and Presentation corner queue. Private rolls appear only to the roller
  and Control; Control may reveal them later.
  - Spectators see broadcasts, private Control messaging, polls, the public roll feed, revealed roster/notes, and
  the current map. They cannot roll, join Player groups, edit notes, or claim a PC.

  ## 3. Architecture, interfaces, and security

  - Use relational tables for authored entities, authorization-sensitive runtime records, messages, votes, rolls,
  notes, and claims. Use JSONB only for immutable revision manifests, aggregate presentation/map snapshots, and
  structured roll breakdowns.
  - Give campaign content stable UUIDv7 identifiers across revisions.
  - Maintain revisioned runtime aggregates for Presentation, map progress, and overlay lanes. Every mutating command
  carries `command_id` and `expected_revision`; duplicate commands are idempotent and stale revisions return a
  conflict plus the current revision.
  - Maintain an append-only session event log with actor, command ID, event type, timestamp, and affected aggregate.
  Relational state remains the query source of truth.
  - Expose versioned, same-origin JSON APIs under `/api/control/v1`, `/api/participant/v1`, and `/api/presentation/
  v1`.
  - Define an OpenAPI 3.1 contract and generate the TypeScript client/types with `openapi-typescript`/`openapi-
  fetch`; CI fails if generated code is stale or backend contract tests diverge.
  - Shared public enums/types include participant role, audience target, cue readiness, scene reset mode,
  transition, facing, overlay placement, roll visibility, music-during/after policies, and revision status.
  - Pusher uses authenticated private/presence channels. Clients never emit trusted client events; every write goes
  through authorized HTTP commands.
  - Pusher events contain only IDs, event type, and revision/delta data below its documented [10 KB event limit]
  (https://pusher.com/docs/channels/server_api/http-api/). Assets and large snapshots are fetched through authorized
  API/signed-storage URLs.
  - Persist broadcasts through a transactional outbox and publish after commit on a high-priority queue. On
  disconnect/provider failure, clients poll relevant snapshots every two seconds, display degraded status, and
  refetch after reconnect or any revision gap.
  - Control authentication:
    - Create one internal Control principal with no registration/account-management UI.
    - Authenticate the environment-held `CONTROL_SECRET` with constant-time comparison, secure cookie sessions, CSRF
  protection, strict rate limiting, and production startup validation for secret strength.
    - Use [Laravel Fortify’s first-party passkey support](https://laravel.com/docs/13.x/fortify#passkeys) for
  multiple labeled passkeys.
    - Require recent environment-secret confirmation to add/revoke passkeys; the environment secret remains
  recovery.
  - Participant access:
    - Joining requires the active session code and a case-insensitively unique display name.
    - Issue a high-entropy resume token, store only its hash, and place the token in a Secure/HttpOnly/SameSite
  cookie.
    - PC claims are transactional and exclusive. Spectator selection is unlimited. Control can release claims or
  revoke participants.
  - Presentation access:
    - Exchange the pairing URL token for a Secure/HttpOnly display cookie and remove the token from the visible URL.
    - Rotation immediately revokes old display credentials.
    - Treat the most recently paired connected display as primary and warn Control if duplicates connect.
  - Use direct multipart object-storage uploads, immutable checksum-addressed assets, signed reads, MIME/magic-byte
  verification, dimension/duration extraction, and configurable limits. Defaults: 25 MiB images, 250 MiB audio, 2
  GiB video, and 5 GiB packages.
  - Harden package imports against path traversal, symlinks, duplicate paths, checksum mismatch, decompression
  bombs, unsupported schema versions, executable content, and partial database/object-storage writes.
  - Add CSP, secure headers, output escaping, request validation, per-action authorization policies, throttles for
  join/roll/message/poll/fog operations, structured audit logs, readiness/liveness endpoints, and queue-failure
  visibility.

  ## 4. Implementation sequence

  1. **Repository and quality foundation**
     - Initialize Git, Laravel, the three Vue entry points, shared modules, Docker services, CI, environment
  validation, architectural documentation, and the all-in-one quality command.
     - Persist this plan and short ADRs for SPA separation, revisions, realtime source-of-truth, and participant
  authentication.

  2. **Authentication and campaign authoring**
     - Deliver complete Control secret login, passkey lifecycle, campaign CRUD, asset uploads, PC/NPC editors,
  scene/map/audio/video/dice editors, and authorization.
     - Do not expose unfinished navigation or endpoints.

  3. **Revisions and packages**
     - Implement publish validation, immutable manifests/assets, revision comparison/adoption preflight, package
  export/import, and round-trip compatibility fixtures.

  4. **Live-session and realtime core**
     - Implement session lifecycle, participant joining/claims, pairing, groups and optional transfer, revisioned
  command handlers, snapshots, event log, transactional outbox, Pusher/Reverb adapters, reconnect, and polling
  fallback.

  5. **Presentation and Control performance tools**
     - Implement the shared Konva renderer, standby/Go, scene/reset/backdrop behavior, NPC staging/presets/tweens,
  media engine, video policies, soundboards, overlay lanes, and failure recovery.

  6. **Player interactions and maps**
     - Complete Player/Spectator PWA flows, roster/NPC notes, messaging, targeting, polls, dice, public feeds, fog
  rendering, and Control-only multi-token map editing.

  7. **Production hardening**
     - Complete accessibility, browser compatibility, media/package stress tests, load testing, observability,
  backup/restore rehearsal, deployment documentation, and release acceptance.

  ## 5. Extensive test and completion gates

  ### Automated test layers

  - **Backend Pest tests**
    - Unit/property tests for dice parsing/evaluation, targeting, cue/video/music policy reducers, scene reset
  modes, overlay queues, fog operations, revision compatibility, and package validation.
    - Feature tests for every API success, validation, authentication, authorization, throttling, conflict,
  idempotency, and audit path.
    - Concurrency tests proving only one simultaneous PC claim succeeds, duplicate commands/votes are idempotent,
  and competing state revisions cannot overwrite one another.
    - Package export/import round trips plus malicious ZIP, checksum, schema, missing-asset, duplicate-ID, and
  rollback cases.
    - Storage tests with fake S3 and integration tests with MinIO.
    - Broadcast/outbox tests for after-commit publication, retry, event ordering, payload-size limits, and
  authorization.
  - **Frontend Vitest + Vue Testing Library**
    - Test Pinia stores with real actions, revision-gap recovery, reconnect/polling, role-based navigation, cue
  readiness, overlay queues, and optimistic conflicts.
    - Use fake clocks/media adapters to exhaustively test BGM/SFX/video behavior and transition completion/error
  paths.
    - Test normalized 16:9 geometry, facing, z-order, tween interpolation, fog compositing, token multi-select/
  marquee/group movement, pan/zoom, and resize/letterboxing.
    - Test PWA install/update/reconnect behavior and confirm service workers never cache authenticated API responses
  or private media URLs.
  - **Playwright multi-browser E2E**
    - Run Chromium, Firefox, and WebKit plus desktop and mobile profiles. Use multiple isolated browser contexts for
  Control, Presentation, Players, and Spectators.
    - Exercise environment-secret login, virtual-WebAuthn passkey registration/login/revocation, session creation,
  pairing/audio unlock, simultaneous PC claims, Spectator joins, reconnect tokens, and participant revocation.
    - Verify standby Ready/Error/Go, scene entry, both reset modes, alternate backdrops, NPC state/facing/staging,
  tweened presets, audio controls, each video policy combination, Abort, and recovery from bad media.
    - Verify private/public/revealed rolls, simultaneous corner/full overlays, queue/pin/dismiss behavior, named
  published replies, polls and live/final results.
    - Verify direct/group/broadcast permissions and negative attempts at interplayer DM, unauthorized channels,
  hidden NPCs, Player token movement, Spectator writes, and cross-campaign access.
    - Verify fog drawing, persistence, reset, token visibility under fog, group movement, session progress resume/
  fresh behavior, and optional group transfer without message transfer.
    - Verify draft publish, explicit revision adoption, active-state preservation, package round trip, Pusher
  disconnect polling fallback, expired signed URLs, browser refresh, and Presentation replacement.
  - **Visual/accessibility tests**
    - Screenshot regression at 1920×1080 Presentation, standard Control desktop sizes, and representative iOS/
  Android Player viewports.
    - Run axe accessibility scans, keyboard-only navigation, focus management, reduced-motion behavior, contrast,
  labels, and screen-reader status announcements.
  - **Performance/resilience**
    - Load test one Control, one Presentation, and 30 participants with simultaneous joins, poll voting, message
  bursts, public rolls, fog strokes, and reconnect storms.
    - Require API p95 below 250 ms for ordinary commands and control-to-display state visibility below 750 ms in
  staging, excluding media download/preload time.
    - Test database/Redis/Pusher/object-storage/queue interruptions independently and verify explicit degraded/error
  states without silent data loss.
    - Run production-Pusher smoke tests in staging while keeping normal CI deterministic through Reverb.

  ### Quality gates

  - Backend: Laravel Pint check, Larastan/PHPStan at maximum practical strictness, Pest, architecture/dependency
  rules, Composer audit, and Infection mutation tests on critical domain services.
  - Frontend: ESLint, Prettier check, `vue-tsc --noEmit`, Vitest, `knip` dead-code detection, `jscpd` duplication
  checks, dependency audit, production build, and Playwright.
  - Coverage minimums:
    - Backend: 90% lines and 85% branches.
    - Frontend: 85% lines and 80% branches.
    - 100% branch coverage for authorization policies, dice evaluator, cue/reset/video state reducers, participant
  claims, poll targeting, package validator/import, and revision adoption.
    - Critical mutation-test score of at least 80%.
  - Test names must identify the unit/behavior under test. Tests must be deterministic, isolated, and free of
  external Pusher/S3 dependencies except explicit staging suites.
  - CI fails on `TODO`, `FIXME`, `HACK`, `XXX`, `@todo`, “not implemented” exceptions, empty handlers, fake success
  responses, commented-out production code, focused tests, skipped tests, placeholder business logic, or stale
  generated contracts.
  - Visual placeholders are allowed only as clearly labeled non-art UI/test assets; they cannot substitute for
  behavior.
  - A feature is either fully implemented, authorized, documented, and tested or absent. No stub routes, disabled
  controls implying future behavior, mock production services, or “coming soon” screens.
  - Release requires a clean checkout to pass the single quality command, all migrations from an empty database,
  package round-trip fixtures, production build, backup/restore rehearsal, browser suite, 30-participant load test,
  and operator/admin/player documentation.
