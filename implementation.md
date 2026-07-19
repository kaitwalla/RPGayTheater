# Implementation Plan: Theatrical RPG Orchestration Platform

## Purpose

This is the delivery plan for the product described in `plan.md`. It turns the
product specification into independently shippable, testable implementation
increments. It is intentionally a presentation-and-participation platform,
not a virtual tabletop: it must not acquire character-sheet, combat,
initiative, player-token-control, measurement, or pathfinding features.

## Delivery principles

- Build vertical slices. A UI is delivered only with its API, policy,
  persistence, realtime/fallback behavior, audit trail, and tests.
- The database and authenticated HTTP commands are authoritative. Realtime is
  a notification path; clients repair from versioned snapshots.
- Keep authored content mutable in drafts and immutable once published. A live
  session always identifies the revision it is using.
- Treat every state-changing command as an idempotent, optimistic-concurrency
  operation: require `command_id` and `expected_revision`, persist the command
  result, and return a conflict snapshot for a stale revision.
- Complete a navigable capability end-to-end before exposing it. Do not create
  future-facing routes, disabled controls, mock success responses, or TODO
  implementations.
- Start with neutral labelled UI and technical media fixtures only. Art is not
  in scope.

## 1. Repository foundation

### 1.1 Bootstrap

Create a Laravel 13/PHP 8.4 application with PostgreSQL, Redis, S3-compatible
storage, queue workers, scheduler, and Reverb available through Docker Compose.
Use separate development and test configurations; the test stack must have no
dependency on external Pusher or S3.

Set up these top-level areas:

```
app/Domain/                 domain commands, aggregates, policies, reducers
app/Http/                   versioned API controllers, requests, resources
app/Jobs/                   outbox dispatch and asynchronous media work
app/Models/                 relational persistence models
app/Services/               storage, package, realtime, media metadata adapters
database/migrations/        schema
openapi/                    hand-authored OpenAPI 3.1 source and generated client input
resources/control/          Control Vue SPA
resources/presentation/     Presentation Vue SPA
resources/participant/      Player/Spectator Vue PWA
resources/shared/           generated API client, shared types, renderer, stores/utilities
tests/                      Pest, browser, fixture, and contract tests
docs/                       ADRs, operations, deployment, backup/restore guides
```

Configure Vite to build three independently mounted apps and a shared TypeScript
workspace. Use Vue Router, Pinia, Tailwind, Konva/vue-konva, Vitest, Vue Testing
Library, Playwright, ESLint, Prettier, `vue-tsc`, `knip`, and `jscpd`.

### 1.2 Guardrails and operational baseline

- Add strict environment validation at boot. In production, reject weak or
  missing `CONTROL_SECRET`, storage, database, Redis, broadcast, and signing
  configuration.
- Add liveness and readiness endpoints; readiness verifies database, Redis,
  storage, and queue configuration without leaking credentials.
- Configure JSON structured logs with request, actor, session, campaign,
  command, and correlation IDs.
- Add CSP, secure headers, cookie configuration, CSRF middleware, global JSON
  error envelopes, request IDs, and rate-limit infrastructure.
- Configure Pest, Pint, Larastan/PHPStan, architecture rules, Composer audit,
  frontend checks, coverage collection, mutation-test selection, and a single
  `quality` command for local and CI use.
- Create CI jobs for PHP/static checks, frontend/static checks, generated API
  freshness, unit/feature suites, browser suites, builds, security audits, and
  later load/visual suites. Make generated-code drift a hard failure.
- Record ADRs for SPA separation, draft/revision data ownership, HTTP as the
  realtime source of truth, participant authentication, and aggregate
  concurrency.

**Exit criteria:** a clean checkout starts the complete local stack, builds all
three SPAs, runs a small authenticated API smoke test, and passes the foundation
quality command.

## 2. Domain model and API contract first

### 2.1 Stable authored identities and revisions

Use UUIDv7 IDs. Draft entities retain their stable IDs when published;
`campaign_revisions` stores an immutable manifest, manifest checksum, publish
metadata, and its asset manifest. Never mutate a published revision or an
immutable referenced asset.

Model mutable authoring data in normalized tables, including campaigns, drafts,
assets, PCs, NPCs, NPC states, scenes, scene backdrops, stage presets and
entries, maps, fog masks, map tokens, music cues, SFX cues, video cues, and dice
presets. Use JSONB only for revision manifests, runtime snapshots, and roll
breakdowns.

### 2.2 Runtime aggregates and auditability

Model sessions separately from campaigns and revisions. Core runtime tables are:

- `live_sessions`, session codes/history, presentation credentials and display
  connections;
- participant identities/resume-token hashes, PC claims, and session-scoped
  groups/memberships;
- revisioned `presentation_states`, `map_progresses`, and `overlay_lanes`;
- reveals, NPC notes, messages/conversations, polls/recipient snapshots/votes,
  rolls/dice results, and published spectator replies;
- `session_events` as append-only audit/event log, `processed_commands` for
  idempotency, and `outbox_messages` for transactional broadcasts.

Every aggregate has a monotonically increasing revision. Commands validate
policy and expected revision in one transaction, write relational source data,
append an event, record a command result, increment the aggregate, and enqueue
an outbox row. Repeated command IDs return their original response. A stale
revision returns `409` with the current aggregate revision and a snapshot URL
or compact snapshot.

### 2.3 Contract conventions

Define OpenAPI 3.1 before each vertical slice, generate the shared client with
`openapi-typescript` and `openapi-fetch`, and use it in all SPAs. Keep route
families under:

- `/api/control/v1` for Control-only authoring and live commands;
- `/api/participant/v1` for join, player/spectator state, and permitted actions;
- `/api/presentation/v1` for pairing, preload acknowledgement, state, and
  playback-related reporting.

Use common schemas/enums for roles, targets, readiness, reset mode,
transitions, facing, overlays, roll visibility, media policies, revision state,
command envelopes, conflicts, and snapshots. Contract tests must compare
controller responses and authorization failures with the OpenAPI document.

**Exit criteria:** migrations create the core identity/audit/concurrency model
from an empty database; generated TypeScript is used by a contract-tested sample
endpoint; duplicate and stale-command behavior is proven by feature tests.

## 3. Control authentication and campaign authoring

### 3.1 Control access

Implement the single internal Control principal. Authenticate
`CONTROL_SECRET` with constant-time comparison, secure server-side session
cookies, CSRF protection, and strict login throttling. Add Fortify passkeys with
labeled registration, sign-in, revocation, and a recent environment-secret
confirmation requirement for passkey changes. The environment secret remains
the recovery mechanism; there is no registration or account-management UI.

### 3.2 Asset pipeline

Implement direct multipart uploads to S3-compatible storage. Verify declared
MIME type and magic bytes, calculate an immutable checksum-addressed key,
extract image dimensions/audio-video duration, enforce configured limits, and
make reads signed and short-lived. Persist upload status/errors and prevent an
asset from being referenced until validation succeeds.

### 3.3 Draft editors

Build Control routes only for the fully working editors below:

1. Campaign list/create/rename/archive and draft selection.
2. Private asset library with upload, metadata, validation feedback, and safe
   deletion rules.
3. PCs and NPCs (normal/state images, public profile fields, pronouns, and
   native-facing metadata).
4. Scenes, primary/alternate backdrops, default music, transitions, and base
   stage preset selection.
5. Stage presets with complete NPC placement/state/scale/layer/facing/tween
   data and unrestricted roster size.
6. Maps, initial fog, authored PC/NPC/custom token layouts.
7. Music, SFX, video cues and their policies, plus dice presets/defaults.

Validate all references and delete/archive behavior at the API, not only in the
form. Add policies so all content is private to its campaign.

**Exit criteria:** Control can create a fully valid authored draft using only
real assets, and every authoring API has validation, authorization, and
cross-campaign access tests.

## 4. Publish, revision adoption, and content packages

### 4.1 Publishing

Implement a single publish validator that checks the complete graph: required
NPC normal imagery, asset readiness/type, references, map/fog/token consistency,
cue policy validity, bounds, stable-ID uniqueness, and manifest serializability.
On success, transact the immutable revision and asset manifest. Produce a
human-readable validation report before mutation.

### 4.2 Revision adoption

When a newer revision exists, calculate and display a compatibility preflight
against the live session: removed/changed active scene, stage, NPC, map, asset,
and media references. Adoption is explicit. Preserve current runtime media and
stage state; only future loads resolve against the adopted revision. Reject
adoption where the required active runtime references cannot be preserved.

### 4.3 ZIP64 import/export

Export a selected published revision as a content-only ZIP64 archive containing
schema version, metadata, manifest, checksums, and source media. Exclude every
session/participant/runtime/private datum named in the source plan.

Import into a new draft only. First inspect the entire archive and reject path
traversal, symlinks, duplicate paths/IDs, unknown schema, executable content,
checksum or asset failures, excessive expansion, and over-limit packages. Stage
objects and database data, then atomically commit or roll back both; clean up
uncommitted objects on failure.

**Exit criteria:** valid revisions round-trip exactly through fixtures, and
malicious/invalid archives leave neither draft rows nor retained objects.

## 5. Live session, identity, and reliable delivery

### 5.1 Session lifecycle

Create a session from a selected revision with explicit resume or fresh-progress
mode. Resume only map/reveal/note progress; reset scene/audio/overlays/claims,
conversations/polls/rolls as specified. Optionally copy named-group names and
PC memberships from the immediate prior session, without messages; default it
based on resume/fresh mode. Generate rotatable friendly player codes and
separate high-entropy presentation pairing URLs.

### 5.2 Participant and display access

- Participant join validates code and case-insensitively unique entered display
  name. Store only a hash of a high-entropy resume token in a secure,
  HttpOnly, SameSite cookie.
- PC claims use a database uniqueness constraint plus transaction/retry logic;
  only one concurrent claimant succeeds. Spectators are unlimited. Control can
  release claims or revoke access.
- Pairing exchanges the URL token for a display cookie, immediately removes the
  token from the address bar, and invalidates old credentials on rotation.
  Track connected displays; the latest paired connected display is primary and
  duplicate displays are surfaced to Control.

### 5.3 Realtime and fallback

Implement a broadcaster interface with Pusher production and Reverb local/test
adapters. Use private/presence channels with authorization endpoints. Outbox
jobs publish only after commit, retry safely, maintain ordering per aggregate,
and keep payloads under 10 KB by sending IDs/revisions/deltas only.

Clients subscribe for hints, fetch authorized snapshots for initial load and
revision gaps, poll their relevant snapshot every two seconds during an outage,
and visibly indicate degraded/reconnected state. No client event is trusted and
no API mutation relies on a websocket.

**Exit criteria:** isolated Control, Presentation, Player, and Spectator
contexts can join/pair/reconnect; duplicate commands and claims are safe;
realtime interruption visibly falls back to polling without losing state.

## 6. Shared stage, Presentation, and Control live tools

### 6.1 Shared rendering and media abstractions

Create a single Konva rendering module shared by Control preview and
Presentation. Its input is a versioned stage view model with normalized 16:9
geometry, backdrop, NPC staging, fog/map state, and transition parameters.
Presentation owns a 1920x1080 logical canvas and scales/letterboxes it. Test
geometry, z-order, facing, tween interpolation, resize, and reduced motion.

Define testable interfaces for BGM, SFX, video, preload, transition, and
fullscreen/audio unlock behavior. The browser adapters use native media/Web
Audio; fake-clock adapters make all policy paths deterministic in Vitest.

### 6.2 Cue lifecycle and stage control

Implement the following in this order:

1. Presentation pairing, initial snapshot, launch gesture, fullscreen/audio
   unlock, and connection/error reporting.
2. Standby preload/decode handshake and Ready/Error state. Go only operates on
   Ready; Quick Go requires readiness reported by the active display. Pre-Go
   failures never disturb the running stage.
3. Scene entry: stop video, install primary backdrop, restart scene-default BGM
   from zero, and apply optional base preset or empty staging.
4. Cut, fade-through-black, cross-dissolve, alternate-backdrop switching, both
   reset modes, and WYSIWYG freeform NPC editing/preset tweening.
5. BGM single-active-track controls and SFX overlap/loop/volume/stop controls.
6. Full-screen video capture/restore behavior, every configured completion and
   music policy, mute/volume, Abort, and bad-media recovery.

### 6.3 Overlay lanes

Persist independent `corner` and `full` queues. Implement defaults, target
payloads, duration, pin, advance, dismiss, queue ordering, and simultaneous
display. Presentation never invents queue state; it applies the aggregate
snapshot and reports recoverable playback/readiness failures.

**Exit criteria:** Control preview and Presentation produce equivalent stage
output; all scene/video/audio and overlay policies are unit-tested and exercised
in multi-context browser tests.

## 7. Player/Spectator interactions and maps

### 7.1 Participant PWA

Build a mobile-first Player/Spectator SPA with install support. Cache only the
public application shell; explicitly exclude authenticated API responses and
signed/private media URLs. On offline/realtime loss, show a reconnect state and
do not present stale UI as writable.

Implement role-specific navigation and API policies so Player capabilities are
claim, permitted messages/groups, notes, polls, dice, public feed, roster, and
read-only map; Spectators get broadcasts/private Control replies, polls, public
feed, revealed roster/notes, and read-only map only.

### 7.2 Communication, notes, polls, and rolls

- Implement plain-text-only messages with server escaping/length/throttle
  controls. Enforce target matrix: groups are two-way for members/Control;
  broad replies go only to Control; no participant-to-participant DM endpoint.
- Implement explicitly revealed NPC profiles and timestamped note entries.
  Authors may modify their session entries, Control may moderate, and archived
  entries are Control-editable only.
- Implement polls with audience recipient snapshots, single/multiple selection,
  idempotent changing votes until close, live Control aggregation, and
  anonymous aggregate publication.
- Implement a bounded server-side dice parser/evaluator supporting integer and
  `NdM`, `+`, `-`, parentheses, and `kh`/`kl` only. Use CSPRNG, store all/kept/
  dropped dice, enforce the stated character/dice/side limits and throttles,
  then route public/private/revealed results to the correct feeds and overlays.
- Implement Control publication of selected named Spectator replies to the full
  overlay lane.

### 7.3 Maps and fog

Implement a map aggregate that starts from authored fog/token data and maintains
progress independently. Control sees translucent fog and may reveal/hide with
brushes, reset to authored defaults after confirmation, and move only tokens.
Provide modifier multi-select, marquee, group drag preserving offsets,
keyboard-accessible selection, numeric input/nudges, and conflict recovery.

Player and Spectator maps are read-only pan/zoom. Composite fog server/client
consistently so tokens under unrevealed fog never enter the participant model or
render tree. Do not add grids, distance math, movement rules, or player token
dragging.

**Exit criteria:** role and targeting negative tests pass; concurrent votes and
rolls are auditable; fog, token visibility, reset, persistence, and Control-only
editing work in browser and component tests.

## 8. Hardening, operations, and release

### 8.1 Test programme

Expand each slice’s tests toward the coverage and mutation thresholds from
`plan.md`. Keep the mandated 100% branch coverage on policies, dice, media
reducers, claims, poll targeting, package validation/import, and revision
adoption. Add deterministic fake adapters for media/broadcast/storage unless a
test is explicitly integration/staging.

Add Playwright projects for Chromium, Firefox, WebKit, desktop, and mobile
profiles. Include multi-context workflows for Control, primary/replacement
Presentation, players, and spectators. Add screenshots at 1920x1080 and target
desktop/mobile viewports, axe scans, keyboard/focus/reduced-motion checks, and
screen-reader status assertions.

### 8.2 Resilience and performance

Run a 30-participant scenario with joins, voting, message bursts, rolls, fog
strokes, and reconnect storms. Establish staging budgets of p95 under 250 ms for
ordinary APIs and control-to-display visibility under 750 ms excluding media
download/preload. Exercise independent database, Redis, queue, object-storage,
and broadcaster failures; every failure must be observable and recoverable or
clearly reported.

### 8.3 Operator readiness

Document configuration, deployment, migrations, worker/scheduler supervision,
Pusher/Reverb selection, storage lifecycle, key/secret rotation, health checks,
logs, backup schedules, restore procedure, package handling, and incident
triage. Rehearse an empty-database migration plus a database/object-storage
backup restore. Add queue failure visibility to Control.

**Release criteria:** a clean checkout passes the one quality command; all
migrations work from empty state; production builds and package fixtures pass;
the browser, accessibility, load, backup/restore, and staging-Pusher smoke
suites succeed; and Control/operator/player documentation is complete.

## Suggested implementation order within each slice

For each capability, work in this order: schema and domain invariant, OpenAPI
schema, policy/request validation, command handler and transactional event/outbox
write, snapshot/query endpoint, generated client/store, UI, realtime hint and
polling repair, then unit/feature/component/E2E tests. This prevents a polished
interface from becoming the first definition of behavior and makes the
authoritative state model reviewable before browser work begins.
