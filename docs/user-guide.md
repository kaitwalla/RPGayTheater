# Control, Presentation, and Player guide

## Control

Sign in at `/control` with the operator-provided Control secret. Create or
open a campaign draft, author assets and content, then publish only after the
preflight reports every referenced asset ready. Start a live session from the
published revision and distribute its Player code and separate Presentation
pairing token through the intended private channels.

Control owns live changes: it selects or hides the Player map, reveals NPCs,
manages participant claims and revocation, sends messages and polls, controls
overlays, and uses Standby then Go for scenes and video. When realtime is
degraded, wait for the displayed polling state to converge instead of repeating
commands; every accepted command is idempotent by command ID.

## Presentation

Open `/presentation` on the display, enter the one-time pairing token, then
perform the browser's **Enable sound** gesture before the session starts.
Presentation is the sole audio producer, so use its machine for screen-share
audio. It reports standby decode readiness to Control; if a cue reports an
error, Control can keep the live scene untouched and correct the cue before
trying again.

## Player and Spectator

Open `/player`, enter the Player code and an unused display name, and select
the appropriate role. Save the issued resume token as directed by the browser;
it restores the same participant identity after a refresh. Players can claim a
PC, add their own shared NPC notes, message Control and their named groups,
vote, and roll where enabled. Spectators are deliberately read-only for claims,
rolls, group membership, and note edits, while retaining the authorized map,
roster, polls, broadcasts, and private Control conversation.

The Player PWA caches only its application shell. When it reports offline,
wait to reconnect before sending a command; authenticated API data and private
media are never served from the cache.
