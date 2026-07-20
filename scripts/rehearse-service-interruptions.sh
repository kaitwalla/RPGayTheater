#!/usr/bin/env bash

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/docker-compose.yml"
readonly ISOLATED_COMPOSE_FILE="$ROOT_DIR/docker-compose.browser.yml"
readonly PROJECT="rpgays-resilience-test"
readonly MARKER="resilience-$(date -u +%Y%m%dT%H%M%SZ)"

compose=(docker compose -p "$PROJECT" -f "$COMPOSE_FILE" -f "$ISOLATED_COMPOSE_FILE")

cleanup() {
    set +e
    "${compose[@]}" down --volumes --remove-orphans
}

bootstrap_storage() {
    for _attempt in $(seq 1 30); do
        if "${compose[@]}" run --rm --no-deps --entrypoint /bin/sh minio-bootstrap -c 'mc alias set local http://minio:9000 minioadmin minioadmin && mc mb --ignore-existing local/rpgays'; then
            return
        fi

        sleep 1
    done

    echo "MinIO did not become ready for the resilience rehearsal." >&2
    return 1
}

readiness_body() {
    docker run --rm --network "${PROJECT}_default" curlimages/curl:8.12.1 --silent --show-error --max-time 8 http://app:8000/ready
}

liveness_body() {
    docker run --rm --network "${PROJECT}_default" curlimages/curl:8.12.1 --silent --show-error --max-time 3 http://app:8000/live
}

assert_liveness() {
    local body=""

    if ! body="$(liveness_body 2>/dev/null)" || [[ "$body" != *'"status":"alive"'* ]]; then
        echo "Expected /live to remain responsive; received: ${body:-<no response>}" >&2
        return 1
    fi
}

wait_for_ready() {
    for _attempt in $(seq 1 30); do
        if body="$(readiness_body 2>/dev/null)" && [[ "$body" == *'"status":"ready"'* ]]; then
            return
        fi

        sleep 1
    done

    echo "The application did not become ready during the resilience rehearsal." >&2
    return 1
}

assert_degraded() {
    local dependency="$1"
    local body=""

    for _attempt in $(seq 1 15); do
        if body="$(readiness_body 2>/dev/null)"; then
            if [[ "$body" == *'"status":"degraded"'* ]] \
                && [[ "$body" == *"\"${dependency}\":\"unavailable\""* ]]; then
                return
            fi
        else
            assert_liveness
            echo "/ready timed out while ${dependency} was unavailable; /live remained responsive." >&2
            return
        fi

        sleep 1
    done

    echo "Expected /ready to report ${dependency} unavailable; received: ${body:-<no response>}" >&2
    return 1
}

create_event() {
    local phase="$1"

    "${compose[@]}" exec -T app php artisan tinker --execute="App\\Models\\OutboxEvent::query()->create(['aggregate_type' => 'resilience', 'topic' => 'control.campaigns', 'payload' => ['event_type' => 'resilience.probe', 'marker' => '${MARKER}-${phase}', 'revision' => 1], 'occurred_at' => now()]);"
}

wait_for_event() {
    local phase="$1"
    local condition="$2"

    for _attempt in $(seq 1 45); do
        if "${compose[@]}" exec -T app php artisan tinker --execute="\$event = App\\Models\\OutboxEvent::query()->where('payload->marker', '${MARKER}-${phase}')->firstOrFail(); if (! (${condition})) { throw new RuntimeException('event state has not converged'); }" > /dev/null 2>&1; then
            return
        fi

        sleep 1
    done

    echo "Outbox event ${phase} did not reach the expected state." >&2
    return 1
}

trap cleanup EXIT

"${compose[@]}" down --volumes --remove-orphans
"${compose[@]}" up --build -d app worker reverb
bootstrap_storage
"${compose[@]}" exec -T app php artisan migrate --force
wait_for_ready

"${compose[@]}" stop postgres
assert_degraded database
"${compose[@]}" start postgres
wait_for_ready

"${compose[@]}" stop redis
assert_degraded cache
assert_degraded queue
"${compose[@]}" start redis
"${compose[@]}" up -d worker
wait_for_ready

"${compose[@]}" stop minio
assert_degraded storage
"${compose[@]}" start minio
wait_for_ready

"${compose[@]}" stop worker
create_event queue-paused
wait_for_event queue-paused '$event->dispatched_at === null && $event->last_error === null'
"${compose[@]}" start worker
"${compose[@]}" exec -T app php artisan outbox:dispatch
wait_for_event queue-paused '$event->dispatched_at !== null'

"${compose[@]}" stop reverb
create_event reverb-unavailable
wait_for_event reverb-unavailable '$event->dispatched_at === null && $event->last_error !== null'
"${compose[@]}" start reverb
"${compose[@]}" exec -T app php artisan outbox:dispatch
wait_for_event reverb-unavailable '$event->dispatched_at !== null && $event->last_error === null'

echo "Service-interruption resilience rehearsal passed for ${MARKER}."
