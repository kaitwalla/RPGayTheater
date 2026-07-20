# Plan implementation audit

This record maps the repository-owned requirements in `plan.md` to their
implementation and automated evidence as of 2026-07-20. It deliberately does
not claim external hosted-Pusher, deployed-environment, or real-device release
evidence; those require provisioned infrastructure and are listed in the
[deployment runbook](deployment.md).

## Foundation and architecture

| Plan area | Implementation | Evidence |
| --- | --- | --- |
| PHP/Laravel, PostgreSQL, Redis, S3-compatible storage, realtime | `backend/Dockerfile`, `docker-compose.yml`, `AppServiceProvider`, Reverb, and the Pusher publisher | `/live` and `/ready` feature tests; isolated resilience rehearsal |
| Separate Control, Presentation, and Player SPAs | `resources/control`, `resources/presentation`, `resources/participant`, shared API/realtime/stage modules | Playwright shell/accessibility suite; ADR-001 |
| Immutable content, runtime source of truth, and credentials | revision/runtime models, command services, outbox, secure principal middleware | ADR-002 through ADR-004; feature tests for idempotency, conflicts, pairing, and revocation |
| Contract and generated shared types | `openapi/openapi.json`, generated `resources/shared/generated/api.ts` | `npm run check:api` and API-contract feature tests |

## Product behavior

| Plan area | Implementation and test evidence |
| --- | --- |
| Campaign authoring, private assets, publish, and packages | Authoring controllers/services/models cover PCs, NPCs/states, scenes, presets, maps/fog/tokens, audio, video, dice, asset validation, manifests, ZIP export/import, and revision adoption. `ControlCampaignApiTest` covers publish, package round trips, malformed archives, asset validation, and adoption protections. |
| Live sessions and participant lifecycle | `LiveSessionService`, participant/presentation controllers, claims, transfer, events, and outbox implement session codes, pairing, resumes, claims, groups, revocation, compatibility preflight, and polling/realtime recovery. Feature and Playwright multi-context claim/pairing/restriction tests cover the authorization-sensitive paths. |
| Presentation and Control tools | `PresentationStateService`, `PresentationRenderService`, `presentation-stage.ts`, `presentation/main.ts`, and Control workspace implement standby/Go, scene/reset/alternate backdrop, staging/presets/tweens, BGM/SFX, video policy/recovery, overlays, and audio unlock. Frontend stage tests and PHP feature tests cover deterministic state changes. |
| Maps, participant interactions, and overlays | Map progress/state services, Konva stages, participant controllers, messaging/polls/rolls/notes services, and overlay state implement fog, token editing, read-only views, role-scoped conversations, polls, dice, and independent lanes. Unit/feature/frontend tests cover geometry, fog, authorization, idempotency, queues, and PWA cache safety. |

## Security and reliability

- Secure Control-secret sessions, recent-secret passkey management, participant
  resume cookies, hashed pairing credentials, CSP/security headers, validation,
  throttling, policies, signed storage reads, MIME/media inspection, and safe
  package validation are implemented in the middleware, requests, services,
  and Fortify provider. `ControlCampaignApiTest`, `ReadinessTest`, S3 tests,
  and channel-authorizer tests exercise these boundaries.
- Revisioned commands, append-only session events, transactional outbox,
  private channels, small realtime invalidations, two-second fallback polling,
  structured request IDs/logs, and readiness/queue diagnostics are implemented
  and covered by outbox, realtime, and frontend fallback tests.
- The implementation is intentionally free of product artwork. Empty states
  are labelled CSS UI and test media remains technical fixtures.

## Completion gates

| Gate | Verified repository evidence |
| --- | --- |
| Backend quality | The single `composer quality` gate passed: Pint, PHPStan level 8, 100 PHP tests / 1,889 assertions, 91.50% lines, 85.29% branches, 81% covered-code mutation score, Composer audit, and OpenAPI freshness. |
| Frontend quality | The same gate passed Prettier, ESLint, `vue-tsc`, Knip, JSCPD, npm audit, 39 frontend tests, 93.55% statements / 80.82% branches, PWA Node tests, and Vite production build. |
| Browser and accessibility | The disposable Playwright stack passed 30 scenarios with no skipped tests across Chromium, Firefox, WebKit, Android Chrome, and iOS Safari profiles. It uses isolated Control/Presentation/Player/Spectator contexts, a Chromium virtual authenticator for passkey registration/login/revocation, axe scans, keyboard map coverage, reduced-motion component coverage, negative role authorization, and deterministic screenshot fingerprints at Presentation 1920×1080, Control desktop, Android Player, and iOS Player viewports. |
| Operational rehearsal | The isolated load rehearsal passed 277 checks with a 51.10 ms ordinary-command p95; backup/restore and database, Redis, MinIO, worker, and Reverb interruption rehearsals passed. Scripts, CI jobs, and operator instructions are in `scripts/`, `.github/workflows/quality.yml`, `operations.md`, and `deployment.md`. |

## External release evidence

Normal CI deliberately uses local Reverb and fixture storage. The repository
includes the staging-only Pusher smoke command and production runbook, but a
hosted Pusher probe, real-device audio/fullscreen confirmation, and production
deployment/restore evidence must be recorded by an operator with those
credentials. They are not represented as completed local evidence.
