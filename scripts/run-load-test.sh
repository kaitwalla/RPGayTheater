#!/usr/bin/env bash

set -euo pipefail

project="rpgays-load-test"
compose=(docker compose -p "$project" -f docker-compose.yml -f docker-compose.load.yml)

cleanup() {
    "${compose[@]}" down --volumes --remove-orphans
}

trap cleanup EXIT

cleanup
"${compose[@]}" config --quiet
"${compose[@]}" up --build -d app
"${compose[@]}" exec -T app php artisan migrate --force
"${compose[@]}" exec -T app php artisan load-test:seed
"${compose[@]}" --profile load run --rm load
