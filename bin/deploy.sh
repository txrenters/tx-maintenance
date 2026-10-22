#!/usr/bin/env bash
# Zero-downtime deployment for tx-maintenance, adapted from tx-chatbot-vps.
#
# Docker Compose has no rolling update of its own: `up -d` stops a container
# before starting its replacement. This script starts each replacement beside
# the old container, waits for it to report healthy, and only then retires the
# old one. If the new build never becomes healthy the deploy stops and the old
# containers keep serving.
#
# Rolled:     web, then app  (user-facing)
#   web first, so the new build's assets are served before any page that
#   references them; the asset history keeps the old ones served as well.
# Recreated:  reverb, queue, scheduler
#   Two schedulers would double-fire scheduled commands, a few seconds without
#   a queue worker is not user-visible, and Echo reconnects on its own when
#   reverb restarts.
#
# `web` can roll because it publishes a two-port range (8090-8091); the old and
# new replica each take one, and the host nginx upstream lists both.
#
# Runs ON the VPS, in /opt/tx-maintenance, against whatever tree the Deploy to
# VPS workflow (.github/workflows/deploy-vps.yml) last synced there.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.yml"
ROLLING_SERVICES=(web app)
HEALTH_TIMEOUT=180

log() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# How many replicas of a service are ready right now. Counts rather than
# tracking container ids, which compose may remove or rename while scaling.
ready_count() {
    local service=$1 id state n=0

    for id in $($COMPOSE ps -q "$service" 2>/dev/null); do
        state=$(docker inspect -f \
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
            "$id" 2>/dev/null) || continue
        [[ "$state" == "healthy" ]] && n=$((n + 1))
    done

    echo "$n"
}

wait_for_ready() {
    local service=$1 want=$2
    local deadline=$((SECONDS + HEALTH_TIMEOUT))

    while (( SECONDS < deadline )); do
        (( $(ready_count "$service") >= want )) && return 0
        sleep 2
    done

    echo "Timed out after ${HEALTH_TIMEOUT}s waiting for $want healthy $service replica(s)." >&2
    return 1
}

# A failed build leaves the running containers untouched.
log "Building images"
$COMPOSE build

# Copy the assets of any web container already running into the history, so
# the build being replaced stays loadable even if it predates the history.
# Streamed through a throwaway container: the running one may not mount it.
log "Keeping the current build's assets"
APP_SLUG=$(grep -E '^APP_SLUG=' .env | cut -d= -f2- | tr -d '"')
HISTORY_VOLUME="${APP_SLUG:-txmaint}_build-history"
docker volume create "$HISTORY_VOLUME" >/dev/null
for id in $($COMPOSE ps -q web 2>/dev/null); do
    docker exec "$id" tar -C /var/www/html/public/build/assets -cf - . \
        | docker run --rm -i -v "$HISTORY_VOLUME:/history" alpine sh -c 'mkdir -p /history/assets && tar -C /history/assets -xf -' \
        || echo "Could not copy assets from $id; carrying on." >&2
done

# Migrations run once, before new code serves traffic, and must be
# backward-compatible: the old containers keep running against the new schema
# until the roll completes. Add columns nullable, drop them a deploy later.
log "Running migrations"
$COMPOSE run --rm migrate

# Fresh host: nothing is running yet. web is rolled first and with --no-deps,
# so on a first deploy it would come up with no app behind it and never turn
# healthy ("app could not be resolved" -> 502). Start app first in that case.
if [[ -z "$($COMPOSE ps -q app 2>/dev/null)" ]]; then
    log "First deploy: starting app before web"
    $COMPOSE up -d --no-deps app
    wait_for_ready app 1
fi

for service in "${ROLLING_SERVICES[@]}"; do
    log "Rolling $service"

    # Converge to one replica first, so a previously failed deploy's leftovers
    # do not skew the doubling below.
    $COMPOSE up -d --no-deps --no-recreate --scale "$service=1" "$service"

    old_ids=$($COMPOSE ps -q "$service" || true)

    if [[ -z "$old_ids" ]]; then
        log "$service is not running yet; starting it"
        $COMPOSE up -d --no-deps "$service"
        wait_for_ready "$service" 1
        continue
    fi

    # --no-recreate leaves the old container serving and adds one from the
    # new image.
    $COMPOSE up -d --no-deps --no-recreate --scale "$service=2" "$service"

    echo "Waiting for the replacement to become healthy..."
    if ! wait_for_ready "$service" 2; then
        echo "Rolling back $service: removing the unhealthy replacement." >&2
        new_ids=$(comm -13 <(echo "$old_ids" | sort) <($COMPOSE ps -q "$service" | sort))
        # shellcheck disable=SC2086
        [[ -n "$new_ids" ]] && docker rm -f $new_ids >/dev/null 2>&1 || true
        exit 1
    fi

    # nginx resolves the app upstream with valid=2s; wait that out before the
    # old container disappears.
    echo "Draining..."
    sleep 5

    echo "Retiring the old container..."
    # shellcheck disable=SC2086
    docker stop $old_ids >/dev/null 2>&1 || true
    # shellcheck disable=SC2086
    docker rm $old_ids >/dev/null 2>&1 || true

    $COMPOSE up -d --no-deps --no-recreate --scale "$service=1" "$service"
    echo "$service: $(ready_count "$service") healthy"
done

# Everything else that is enabled: reverb, queue, and scheduler once its
# profile is switched on. `config --services` honours COMPOSE_PROFILES.
log "Recreating background services"
background=()
for service in $($COMPOSE config --services); do
    [[ " ${ROLLING_SERVICES[*]} migrate " == *" $service "* ]] || background+=("$service")
done
$COMPOSE up -d --no-deps "${background[@]}"

log "Pruning superseded images and stale build cache"
docker image prune -f
docker builder prune -f --filter 'until=24h'

$COMPOSE ps
log "Deployment complete"
