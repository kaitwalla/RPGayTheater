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

cd "$ROOT_DIR"

echo 'Running pre-push quality gate...'
docker compose --profile tools run --rm --build quality

echo 'Running pre-push browser and accessibility gates...'
trap cleanup_browser_stack EXIT
cleanup_browser_stack
"${browser_compose[@]}" up --build -d app
"${browser_compose[@]}" exec -T app php artisan migrate --force
"${browser_compose[@]}" exec -T app php artisan load-test:seed
"${browser_compose[@]}" --profile browser run --rm --build browser
"${browser_compose[@]}" --profile browser run --rm --build browser-passkey
cleanup_browser_stack
trap - EXIT

echo 'Running pre-push backup and restore rehearsal...'
"$ROOT_DIR/scripts/rehearse-backup-restore.sh"

echo 'Running pre-push service-interruption resilience rehearsal...'
"$ROOT_DIR/scripts/rehearse-service-interruptions.sh"

echo 'All GitHub Actions gates passed locally.'
