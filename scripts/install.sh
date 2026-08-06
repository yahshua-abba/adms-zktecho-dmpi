#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SCRIPT_LABEL="Installation stopped"
# shellcheck source=scripts/lib.sh
source "$ROOT_DIR/scripts/lib.sh"

command -v docker >/dev/null 2>&1 || fail "Docker is not installed. Install Docker first."
docker info >/dev/null 2>&1 || fail "Docker is not reachable. Start Docker and check your docker group permissions."
require_compose_v2

step "Create .env if needed"
if [[ ! -f .env ]]; then
    [[ -f .env.example ]] || fail ".env.example is missing."
    cp .env.example .env

    # Sail's MySQL service is named mysql inside the Docker network.
    sed -i.bak \
        -e 's/^DB_HOST=.*/DB_HOST=mysql/' \
        -e 's/^DB_USERNAME=.*/DB_USERNAME=sail/' \
        -e 's/^DB_PASSWORD=.*/DB_PASSWORD=password/' \
        .env
    rm -f .env.bak
    printf 'Created .env with Docker database defaults.\n'
else
    printf 'Keeping the existing .env unchanged.\n'
fi

step "Install PHP dependencies"
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$PWD":/var/www/html \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --no-interaction --prefer-dist

step "Build and start Sail"
./vendor/bin/sail up -d --build

# Before the first artisan call. A first install has nothing to repair, but this
# script is re-runnable and is what someone reaches for when a box is in a bad
# state — and with `set -e`, an artisan command that hits a root-owned file in
# storage aborts the run before any later repair could execute.
step "Give storage back to the app user"
./vendor/bin/sail exec -T -u root laravel.test chown -R sail storage bootstrap/cache
./vendor/bin/sail exec -T -u root laravel.test chmod -R ug+rwX storage bootstrap/cache

step "Install and build frontend assets"
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

step "Generate the Laravel key if needed"
if grep -q '^APP_KEY=.' .env; then
    printf 'APP_KEY is already set. Keeping it unchanged.\n'
else
    ./vendor/bin/sail artisan key:generate
fi

step "Wait for the database"
wait_for_database

step "Run database migrations"
./vendor/bin/sail artisan migrate --force

step "Clear Laravel caches"
./vendor/bin/sail artisan optimize:clear

# -u sail is load-bearing. `sail exec` with no user runs as ROOT, and a scheduler
# started that way writes root-owned files into storage/framework/cache — which
# the dashboard and the watchdog-started scheduler (both the app user) then can't
# open. Every job here holds a cache lock to avoid overlapping itself, so that one
# permission error stops all five before they start: punches stop reaching
# payroll, and the Scheduler page reads "Running, but no job has ever run".
# Observed in the field.
#
# Two calls, and the split matters. `pgrep -f` matches the command line only, not
# the owner, so a root-owned scheduler left over from an older install satisfies
# the "one is already running" guard — the correct one never starts and the box
# keeps making root-owned files forever. Root has to do the stopping, since the
# app user cannot signal root's processes; the app user does the starting.
# The check runs in its OWN exec call, and that is not cosmetic. Putting the
# guard and `nohup php artisan schedule:work` in one shell puts the searched-for
# text on that shell's own command line, so `pgrep -f` finds the shell, concludes
# a scheduler is already running, and starts nothing. Verified against a box with
# no scheduler at all: the previous one-liner reported one was running, which
# means this step had never once started a scheduler. The bracketed `[a]rtisan`
# only stops pgrep matching the pattern itself, not the literal in the payload.
step "Start the scheduler as the app user"
./vendor/bin/sail exec -T -u root laravel.test sh -lc \
    "if ! pgrep -u sail -f '[a]rtisan schedule:work' >/dev/null 2>&1; then pkill -f '[a]rtisan schedule:work' >/dev/null 2>&1 || true; sleep 1; fi"
if ! ./vendor/bin/sail exec -T -u sail laravel.test pgrep -f '[a]rtisan schedule:work' >/dev/null 2>&1; then
    ./vendor/bin/sail exec -T -u sail laravel.test sh -lc \
        "nohup php artisan schedule:work >> storage/logs/scheduler.log 2>&1 &"
fi

step "Final checks"
./vendor/bin/sail ps
./vendor/bin/sail artisan migrate:status

if grep -Eq '^PAYROLL_URL=https?://' .env \
    && grep -q '^PAYROLL_USERNAME=.' .env \
    && grep -q '^PAYROLL_PASSWORD=.' .env; then
    printf '\nPayroll settings are present. Run the initial payroll sync when ready:\n'
    printf '  ./vendor/bin/sail artisan payroll:sync-roster\n'
    printf '  ./vendor/bin/sail artisan payroll:sync-devices\n'
    printf '  ./vendor/bin/sail artisan payroll:reconcile-enrollments\n'
else
    printf '\nInstallation complete, but payroll credentials are not fully configured.\n'
    printf 'Edit .env and set PAYROLL_URL, PAYROLL_USERNAME, and PAYROLL_PASSWORD.\n'
    printf 'Then run: ./scripts/install.sh\n'
fi

printf '\nOpen /monitoring and confirm the Scheduler card is green.\n'
