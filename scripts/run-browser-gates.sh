#!/usr/bin/env bash

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/docker-compose.yml"
readonly BROWSER_COMPOSE_FILE="$ROOT_DIR/docker-compose.browser.yml"
readonly BROWSER_PROJECT="rpgays-browser-test"

browser_compose=(docker compose -p "$BROWSER_PROJECT" -f "$COMPOSE_FILE" -f "$BROWSER_COMPOSE_FILE")

cleanup() {
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

trap cleanup EXIT
"${browser_compose[@]}" --profile browser down --volumes --remove-orphans
"${browser_compose[@]}" --profile browser up --build -d minio
"${browser_compose[@]}" --profile browser run --rm minio-bootstrap
"${browser_compose[@]}" --profile browser up --build -d app
"${browser_compose[@]}" --profile browser exec -T app php artisan migrate --force
"${browser_compose[@]}" --profile browser exec -T app php artisan load-test:seed
run_browser_gate browser
run_browser_gate browser-passkey

echo 'Browser and accessibility gates passed.'
