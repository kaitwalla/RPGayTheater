#!/usr/bin/env bash

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/docker-compose.yml"
readonly ISOLATED_COMPOSE_FILE="$ROOT_DIR/docker-compose.browser.yml"
readonly SOURCE_PROJECT="rpgays-rehearsal-source"
readonly RESTORE_PROJECT="rpgays-rehearsal-restore"
readonly REHEARSAL_DIR="$(mktemp -d "${TMPDIR:-/tmp}/rpgays-backup-rehearsal.XXXXXX")"
readonly MARKER="restore-rehearsal-$(date -u +%Y%m%dT%H%M%SZ)"

source_compose=(docker compose -p "$SOURCE_PROJECT" -f "$COMPOSE_FILE" -f "$ISOLATED_COMPOSE_FILE")
restore_compose=(docker compose -p "$RESTORE_PROJECT" -f "$COMPOSE_FILE" -f "$ISOLATED_COMPOSE_FILE")

cleanup() {
    set +e
    "${restore_compose[@]}" down --volumes --remove-orphans
    "${source_compose[@]}" down --volumes --remove-orphans
    rm -rf "$REHEARSAL_DIR"
}

bootstrap_storage() {
    local -a compose_command=("$@")

    for _attempt in $(seq 1 30); do
        if "${compose_command[@]}" run --rm --no-deps --entrypoint /bin/sh minio-bootstrap -c 'mc alias set local http://minio:9000 minioadmin minioadmin && mc mb --ignore-existing local/rpgays'; then
            return
        fi

        sleep 1
    done

    echo "MinIO did not become ready for the restore rehearsal." >&2
    return 1
}

wait_for_ready() {
    local project="$1"

    for _attempt in $(seq 1 30); do
        if docker run --rm --network "${project}_default" curlimages/curl:8.12.1 --fail --silent --show-error http://app:8000/ready; then
            return
        fi

        sleep 1
    done

    echo "The restored application did not become ready." >&2
    return 1
}

trap cleanup EXIT

"${source_compose[@]}" down --volumes --remove-orphans
"${restore_compose[@]}" down --volumes --remove-orphans

"${source_compose[@]}" up --build -d app
bootstrap_storage "${source_compose[@]}"
"${source_compose[@]}" exec -T app php artisan migrate --force
"${source_compose[@]}" exec -T app php artisan tinker --execute="App\\Models\\Campaign::query()->create(['name' => '$MARKER', 'draft_revision' => 1]); Illuminate\\Support\\Facades\\Storage::disk('s3')->put('rehearsals/$MARKER.txt', '$MARKER');"
"${source_compose[@]}" exec -T postgres pg_dump -U rpgays -Fc rpgays > "$REHEARSAL_DIR/database.dump"
"${source_compose[@]}" run --rm --no-deps -v "$REHEARSAL_DIR:/backup" --entrypoint /bin/sh minio-bootstrap -c 'mc alias set source http://minio:9000 minioadmin minioadmin && mc mirror --overwrite source/rpgays /backup/rpgays'

"${restore_compose[@]}" up --build -d app
bootstrap_storage "${restore_compose[@]}"
"${restore_compose[@]}" exec -T postgres pg_restore --clean --if-exists --no-owner -U rpgays -d rpgays < "$REHEARSAL_DIR/database.dump"
"${restore_compose[@]}" run --rm --no-deps -v "$REHEARSAL_DIR:/backup" --entrypoint /bin/sh minio-bootstrap -c 'mc alias set target http://minio:9000 minioadmin minioadmin && mc mirror --overwrite /backup/rpgays target/rpgays'
"${restore_compose[@]}" exec -T app php artisan migrate --force
"${restore_compose[@]}" exec -T app php artisan tinker --execute="if (!App\\Models\\Campaign::query()->where('name', '$MARKER')->exists() || !Illuminate\\Support\\Facades\\Storage::disk('s3')->exists('rehearsals/$MARKER.txt')) { throw new RuntimeException('Restored rehearsal marker is missing.'); }"
wait_for_ready "$RESTORE_PROJECT"

echo "Backup and restore rehearsal passed for $MARKER."
