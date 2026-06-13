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
PGUSER="postgres"
PGPASS="postgres"
PGCONTAINER="mpci-postgres"
CI_VERSION="^4"

log() { printf '\n\033[1;36m[local-ci]\033[0m %s\n' "$*"; }

# Moodle's PHPUnit init requires max_input_vars >= 5000 (CI sets 7000). Drop a
# conf.d ini into the CLI SAPI so local runs match.
ensure_php_ini() {
  local confd
  confd="$(php -i 2>/dev/null | sed -n 's/^Scan this dir for additional .ini files => //p' \
    | tr ',' '\n' | grep -m1 cli || true)"
  [ -z "$confd" ] && confd="/etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/cli/conf.d"
  if [ -d "$confd" ] && [ -w "$confd" ]; then
    printf 'max_input_vars=7000\nmemory_limit=512M\n' > "$confd/99-moodle-ci.ini"
    log "Set CLI max_input_vars=7000 via $confd/99-moodle-ci.ini"
  fi
}

db_up() {
  PGPASSWORD="$PGPASS" psql -h 127.0.0.1 -p "$PGPORT" -U "$PGUSER" -tAc 'select 1' >/dev/null 2>&1
}

# Prefer a Docker Postgres (matches CI exactly); fall back to a locally
# installed cluster when no Docker daemon is available (e.g. this sandbox).
start_postgres() {
  if db_up; then
    log "Postgres already accepting connections on ${PGPORT}."
    return 0
  fi
  if docker info >/dev/null 2>&1; then
    start_postgres_docker
  else
    start_postgres_local
  fi
  log "Waiting for Postgres to accept connections."
  for _ in $(seq 1 30); do
    db_up && return 0
    sleep 1
  done
  echo "Postgres did not become ready in time." >&2
  exit 1
}

start_postgres_docker() {
  docker rm -f "$PGCONTAINER" >/dev/null 2>&1 || true
  log "Starting Postgres 16 (container ${PGCONTAINER}, port ${PGPORT})."
  docker run -d --name "$PGCONTAINER" \
    -e POSTGRES_USER="$PGUSER" -e POSTGRES_PASSWORD="$PGPASS" \
    -p "${PGPORT}:5432" postgres:16 >/dev/null
}

start_postgres_local() {
  if [ "$(id -u)" != "0" ]; then
    echo "No Docker daemon and not root: cannot provision Postgres locally." >&2
    exit 1
  fi
  if ! ls /usr/lib/postgresql/*/bin/initdb >/dev/null 2>&1; then
    log "No Docker daemon; installing PostgreSQL via apt."
    apt-get update -qq && apt-get install -y --no-install-recommends postgresql
  fi
  log "Starting the local PostgreSQL cluster."
  service postgresql start || true
  # Give the bundled 'postgres' role a known password so TCP auth works.
  sudo -u postgres psql -tAc "ALTER USER ${PGUSER} PASSWORD '${PGPASS}';" >/dev/null 2>&1 || true
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
        --plugin "$PLUGIN_DIR" --db-host=127.0.0.1 --db-port="$PGPORT" \
        --db-user="$PGUSER" --db-pass="$PGPASS" )
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
  ensure_php_ini
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
