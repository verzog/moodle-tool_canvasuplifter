#!/usr/bin/env bash
#
# Local mirror of .github/workflows/moodle-ci.yml.
#
# Provisions moodle-plugin-ci + a dockerised Postgres and runs the same checks
# CI runs (phplint, phpcs, phpdoc, validate, savepoints, mustache, phpunit), so
# the assign/page/etc. builders can be validated against a real Moodle before
# pushing. Everything lives outside the repo working tree.
#
# Usage:
#   tooling/local-ci.sh                 # provision if needed, then run all checks
#   tooling/local-ci.sh phpunit         # run a single step (after a prior install)
#   tooling/local-ci.sh --reinstall     # force a clean Moodle reinstall, then all checks
#   MOODLE_BRANCH=MOODLE_502_STABLE tooling/local-ci.sh   # pick a branch
#
# Env overrides: MOODLE_BRANCH (default MOODLE_500_STABLE), WORKSPACE, PGPORT.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKSPACE="${WORKSPACE:-$HOME/.moodle-plugin-ci}"
MOODLE_BRANCH="${MOODLE_BRANCH:-MOODLE_500_STABLE}"
PGPORT="${PGPORT:-5432}"
PGCONTAINER="mpci-postgres"
CI_VERSION="^4"

log() { printf '\n\033[1;36m[local-ci]\033[0m %s\n' "$*"; }

start_postgres() {
  if ! command -v docker >/dev/null 2>&1; then
    echo "docker is required to run the database; not found." >&2
    exit 1
  fi
  if [ -n "$(docker ps -q -f "name=^${PGCONTAINER}$")" ]; then
    log "Postgres container already running."
  else
    docker rm -f "$PGCONTAINER" >/dev/null 2>&1 || true
    log "Starting Postgres 16 (container ${PGCONTAINER}, port ${PGPORT})."
    docker run -d --name "$PGCONTAINER" \
      -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
      -e POSTGRES_HOST_AUTH_METHOD=trust \
      -p "${PGPORT}:5432" postgres:16 >/dev/null
  fi
  log "Waiting for Postgres to accept connections."
  for _ in $(seq 1 30); do
    if docker exec "$PGCONTAINER" pg_isready -U postgres >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "Postgres did not become ready in time." >&2
  exit 1
}

install_ci_tool() {
  if [ ! -x "$WORKSPACE/ci/bin/moodle-plugin-ci" ]; then
    log "Installing moodle-plugin-ci ${CI_VERSION} into ${WORKSPACE}/ci."
    mkdir -p "$WORKSPACE"
    ( cd "$WORKSPACE" && composer create-project -n --no-dev --prefer-dist \
        moodlehq/moodle-plugin-ci ci "$CI_VERSION" )
  fi
}

install_moodle() {
  log "Installing Moodle ${MOODLE_BRANCH} (pgsql) — this downloads Moodle and builds the test DB."
  rm -rf "$WORKSPACE/moodle" "$WORKSPACE/moodledata"*
  ( cd "$WORKSPACE" && DB=pgsql MOODLE_BRANCH="$MOODLE_BRANCH" \
      "$WORKSPACE/ci/bin/moodle-plugin-ci" install \
        --plugin "$PLUGIN_DIR" --db-host=127.0.0.1 --db-port="$PGPORT" )
}

# Keep the installed copy of the plugin in sync with the working tree so a
# code-only change can be retested without a full reinstall.
sync_plugin() {
  local dest="$WORKSPACE/moodle/admin/tool/canvasuplifter"
  if [ -d "$WORKSPACE/moodle" ] && [ ! -L "$dest" ]; then
    log "Syncing plugin source into the Moodle tree."
    rm -rf "$dest"
    mkdir -p "$dest"
    rsync -a --delete --exclude='.git' --exclude='tooling' "$PLUGIN_DIR/" "$dest/"
  fi
}

run_step() {
  local step="$1"
  log "moodle-plugin-ci ${step}"
  ( cd "$WORKSPACE/moodle" && "$WORKSPACE/ci/bin/moodle-plugin-ci" "$@" )
}

run_all() {
  run_step phplint
  run_step phpcs --max-warnings 0
  run_step phpdoc --max-warnings 0
  run_step validate
  run_step savepoints
  run_step mustache
  run_step phpunit --fail-on-warning
  log "All checks passed."
}

main() {
  start_postgres
  install_ci_tool

  local arg="${1:-}"
  if [ "$arg" = "--reinstall" ] || [ ! -d "$WORKSPACE/moodle" ]; then
    install_moodle
    shift || true
    arg="${1:-}"
  fi
  sync_plugin

  if [ -n "$arg" ]; then
    run_step "$@"
  else
    run_all
  fi
}

main "$@"
