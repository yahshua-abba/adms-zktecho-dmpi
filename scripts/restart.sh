#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v docker >/dev/null 2>&1 || {
    printf 'Restart stopped: Docker is not installed.\n' >&2
    exit 1
}

[[ -x vendor/bin/sail ]] || {
    printf 'Restart stopped: vendor/bin/sail is missing. Run ./scripts/install.sh first.\n' >&2
    exit 1
}

docker info >/dev/null 2>&1 || {
    printf 'Restart stopped: Docker is not reachable.\n' >&2
    exit 1
}

printf '==> Restarting Sail containers\n'
./vendor/bin/sail restart

printf '==> Clearing Laravel caches\n'
./vendor/bin/sail artisan optimize:clear

printf '==> Starting the scheduler if it is not already running\n'
./vendor/bin/sail exec -T laravel.test sh -lc \
    "if ! pgrep -f '[a]rtisan schedule:work' >/dev/null; then nohup php artisan schedule:work >> storage/logs/scheduler.log 2>&1 & fi"

printf '==> Checking containers\n'
./vendor/bin/sail ps

printf '\nRestart complete. Open /monitoring and confirm the Scheduler card is green.\n'
