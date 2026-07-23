#!/usr/bin/env bash

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/docker-compose.yml"
readonly BROWSER_COMPOSE_FILE="$ROOT_DIR/docker-compose.browser.yml"
readonly BROWSER_PROJECT="rpgays-browser-test"

browser_compose=(docker compose -p "$BROWSER_PROJECT" -f "$COMPOSE_FILE" -f "$BROWSER_COMPOSE_FILE")

cleanup_browser_stack() {
    set +e
    "${browser_compose[@]}" --profile browser down --volumes --remove-orphans
}

run_browser_gate() {
    local name="$1"
    local log_file

    log_file="$(mktemp -t "rpgays-${name}.XXXXXX.log")"
    if ! "${browser_compose[@]}" --profile browser run --rm --build "$name" 2>&1 | tee "$log_file"; then
        echo "Browser gate '$name' failed; see $log_file for details." >&2
        return 1
    fi

    if grep -Eq '::error file=|[[:space:]][1-9][0-9]* failed' "$log_file"; then
        echo "Browser gate '$name' reported Playwright failures; see $log_file for details." >&2
        return 1
    fi
}

cd "$ROOT_DIR"

echo 'Running pre-push quality gate...'
docker compose --profile tools run --rm --build quality

echo 'Running pre-push browser and accessibility gates...'
trap cleanup_browser_stack EXIT
cleanup_browser_stack
"${browser_compose[@]}" up --build -d minio
"${browser_compose[@]}" run --rm minio-bootstrap
"${browser_compose[@]}" up --build -d app
"${browser_compose[@]}" exec -T app php artisan migrate --force
"${browser_compose[@]}" exec -T app php artisan load-test:seed
run_browser_gate browser || exit 1
run_browser_gate browser-passkey || exit 1
cleanup_browser_stack
trap - EXIT

echo 'Running pre-push backup and restore rehearsal...'
"$ROOT_DIR/scripts/rehearse-backup-restore.sh"

echo 'Running pre-push service-interruption resilience rehearsal...'
"$ROOT_DIR/scripts/rehearse-service-interruptions.sh"

echo 'All GitHub Actions gates passed locally.'
