#!/usr/bin/env bash

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/docker-compose.yml"
cd "$ROOT_DIR"

echo 'Running pre-push quality gate...'
docker compose --profile tools run --rm --build quality

echo 'Running pre-push browser and accessibility gates...'
"$ROOT_DIR/scripts/run-browser-gates.sh"

echo 'Running pre-push backup and restore rehearsal...'
"$ROOT_DIR/scripts/rehearse-backup-restore.sh"

echo 'Running pre-push service-interruption resilience rehearsal...'
"$ROOT_DIR/scripts/rehearse-service-interruptions.sh"

echo 'All GitHub Actions gates passed locally.'
