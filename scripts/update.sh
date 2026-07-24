#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

step() {
    printf '\n==> %s\n' "$1"
}

fail() {
    printf '\nUpdate stopped: %s\n' "$1" >&2
    exit 1
}

command -v git >/dev/null 2>&1 || fail "git is not installed."
command -v docker >/dev/null 2>&1 || fail "Docker is not installed."
[[ -x vendor/bin/sail ]] || fail "vendor/bin/sail is missing. Run Composer install first."

docker info >/dev/null 2>&1 || fail "Docker is not reachable. Check Docker is running and your user is in the docker group."

if ! git diff --quiet || ! git diff --cached --quiet; then
    printf 'Tracked local changes were found:\n'
    git status --short
    fail "Commit or save these changes before pulling."
fi

BEFORE_COMMIT="$(git rev-parse HEAD)"

step "Pull latest repository changes"
git pull --ff-only
AFTER_COMMIT="$(git rev-parse HEAD)"
CHANGED_FILES="$(git diff --name-only "$BEFORE_COMMIT" "$AFTER_COMMIT")"

if printf '%s\n' "$CHANGED_FILES" | grep -q '^\.env\.example$'; then
    printf 'Note: .env.example changed. Review it for new settings; your .env was not changed.\n'
fi

step "Install PHP dependencies"
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$PWD":/var/www/html \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --no-interaction --prefer-dist

if printf '%s\n' "$CHANGED_FILES" | grep -Eq '(^|/)(package\.json|package-lock\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml)$'; then
    step "Install and build frontend assets"
    ./vendor/bin/sail npm install
    ./vendor/bin/sail npm run build
fi

step "Build and restart Sail"
./vendor/bin/sail up -d --build

step "Run database migrations"
./vendor/bin/sail artisan migrate --force

step "Clear Laravel caches"
./vendor/bin/sail artisan optimize:clear

step "Start the scheduler if it is not already running"
./vendor/bin/sail exec -T laravel.test sh -lc \
    "if ! pgrep -f '[a]rtisan schedule:work' >/dev/null; then nohup php artisan schedule:work >> storage/logs/scheduler.log 2>&1 & fi"

step "Final checks"
./vendor/bin/sail ps
./vendor/bin/sail artisan migrate:status

printf '\nUpdate complete. Open /monitoring and confirm the Scheduler card is green.\n'
