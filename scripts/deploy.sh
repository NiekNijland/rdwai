#!/usr/bin/env bash

set -euo pipefail

SCRIPT_START=$SECONDS
MAINTENANCE_MODE=0
APP_MIGRATIONS_PENDING=0
APP_MIGRATIONS_APPLIED=0

log()
{
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

run_step()
{
    local step_name="$1"
    shift

    local start=$SECONDS
    log "Starting: ${step_name}"
    "$@"
    local duration=$((SECONDS - start))
    log "Finished: ${step_name} (${duration}s)"
}

has_pending_migrations()
{
    local pending_output

    if ! pending_output="$(php artisan migrate:status --pending --no-ansi 2>&1)"; then
        if [[ "$pending_output" == *'Migration table not found'* ]]; then
            return 0
        fi

        printf '%s\n' "$pending_output" >&2

        return 1
    fi

    [[ "$pending_output" != *'No pending migrations'* ]]
}

rollback()
{
    if [[ "$APP_MIGRATIONS_PENDING" -eq 1 && "$APP_MIGRATIONS_APPLIED" -eq 1 ]]; then
        log 'Deployment failed after running migrations, attempting rollback'

        if php artisan migrate:rollback --force; then
            log 'Rollback of latest migration batch completed'
        else
            log 'Rollback failed, manual intervention required'
        fi
    fi
}

cleanup()
{
    local exit_code=$?

    if [[ "$exit_code" -ne 0 ]]; then
        rollback
    fi

    if [[ "$MAINTENANCE_MODE" -eq 1 ]]; then
        log 'Disabling maintenance mode'
        php artisan up || true
    fi

    local total_duration=$((SECONDS - SCRIPT_START))

    if [[ "$exit_code" -eq 0 ]]; then
        log "Deploy script completed successfully in ${total_duration}s"
    else
        log "Deploy script failed in ${total_duration}s"
    fi

    return "$exit_code"
}

trap cleanup EXIT

# ── Anchor cwd ───────────────────────────────────────────────────────
# Every subsequent step (`composer install`, `npm ci`, `git`, the
# shared-link block below) assumes cwd is the repository root. Re-anchor
# here so the script behaves the same whether it is invoked as
# `./scripts/deploy.sh`, `bash scripts/deploy.sh`, or via VitoDeploy's
# job runner (which may use an arbitrary working directory).
cd "$(cd "$(dirname "$0")/.." && pwd)"

# ── Update working tree ──────────────────────────────────────────────
# VitoDeploy for this site is configured for in-place updates rather
# than per-release-clone, and its built-in git step has historically
# been a no-op — every deploy reran composer/npm against the original
# checkout, so commits pushed to main were never picked up. We pull
# explicitly here so the deploy is self-healing regardless of how the
# runner invokes us. Override the branch with `DEPLOY_BRANCH=...` if
# you ever cut a staging line.
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
run_step "Fetch origin/${DEPLOY_BRANCH}" git fetch --prune origin "${DEPLOY_BRANCH}"
run_step "Reset working tree to origin/${DEPLOY_BRANCH}" git reset --hard "origin/${DEPLOY_BRANCH}"

# ── Shared resources (VitoDeploy zero-downtime) ──────────────────────
# VitoDeploy clones each release into a fresh directory but does not
# symlink shared resources (.env, storage) automatically. We detect
# the shared source directory and create the symlinks ourselves.
SITE_BASE="$(cd "$(dirname "$0")/../../.." && pwd)"
SHARED_DIR="${SITE_BASE}/source"

link_shared()
{
    local target="$1" link="$2"

    if [[ -e "$target" ]]; then
        rm -rf "$link"
        ln -sfn "$target" "$link"
        log "Linked shared resource: ${link} -> ${target}"
    fi
}

if [[ -d "$SHARED_DIR" ]]; then
    run_step 'Link shared .env' link_shared "${SHARED_DIR}/.env" "$(pwd)/.env"
    run_step 'Link shared storage' link_shared "${SHARED_DIR}/storage" "$(pwd)/storage"
fi

run_step 'Install Composer dependencies' \
    composer install \
        --no-interaction \
        --prefer-dist \
        --no-dev \
        --optimize-autoloader \
        --classmap-authoritative \
        --no-scripts

run_step 'Discover Composer packages' php artisan package:discover --ansi

run_step 'Clear Laravel caches before frontend build' php artisan optimize:clear

run_step 'Install Node dependencies' npm ci --no-audit --no-fund
run_step 'Build frontend and SSR assets' npm run build:ssr

run_step 'Enable maintenance mode' php artisan down --retry=60
MAINTENANCE_MODE=1

if has_pending_migrations; then
    APP_MIGRATIONS_PENDING=1
fi

run_step 'Run database migrations' php artisan migrate --force

if [[ "$APP_MIGRATIONS_PENDING" -eq 1 ]]; then
    APP_MIGRATIONS_APPLIED=1
fi

run_step 'Optimize Laravel caches' php artisan optimize

# OPcache is cleared in check.sh (runs after VitoDeploy switches the symlink,
# so nginx routes to the new release via index.php).

run_step 'Disable maintenance mode' php artisan up
MAINTENANCE_MODE=0
