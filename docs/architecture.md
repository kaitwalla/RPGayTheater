# Architecture decisions

## ADR-001: Separate same-origin SPAs

Control, Presentation, and Player/Spectator are independently mounted Vue
applications at `/control`, `/presentation`, and `/player`. They share typed
transport, realtime, geometry, and stage modules, but separate entry points
keep Control's desktop workflow, Presentation's 16:9 media lifecycle, and the
mobile PWA shell independently deployable and testable without exposing an
unfinished cross-role navigation surface.

## ADR-002: Published revisions are immutable

Campaign authoring changes a mutable draft. Publishing validates it into an
immutable, checksummed manifest that a live session pins. A later published
revision is never applied implicitly: Control must inspect compatibility and
explicitly adopt it. This protects active media, claims, map state, and
auditable session history from draft edits.

## ADR-003: HTTP and relational state are authoritative

All mutations are authorized same-origin HTTP commands with a command ID and
expected revision. Relational runtime state and the append-only event log are
the query source of truth. Realtime messages are intentionally small
invalidation hints published through the after-commit outbox; clients refetch
snapshots after a revision gap, reconnect, or polling fallback.

## ADR-004: Participant credentials are scoped and revocable

Control authenticates an environment-held secret and optional passkeys.
Participant join/resume and Presentation pairing exchange high-entropy tokens
for rotated, secure server sessions. Authorization always resolves the current
session principal, so revocation and display rotation take effect for HTTP and
private-channel checks without trusting browser-provided role data.
