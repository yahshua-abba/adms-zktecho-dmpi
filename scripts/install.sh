#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

step() {
    printf '\n==> %s\n' "$1"
}

fail() {
    printf '\nInstallation stopped: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail "Docker is not installed. Install Docker first."
docker info >/dev/null 2>&1 || fail "Docker is not reachable. Start Docker and check your docker group permissions."

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

step "Install and build frontend assets"
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

step "Generate the Laravel key if needed"
if grep -q '^APP_KEY=.' .env; then
    printf 'APP_KEY is already set. Keeping it unchanged.\n'
else
    ./vendor/bin/sail artisan key:generate
fi

step "Run database migrations"
./vendor/bin/sail artisan migrate --force

step "Clear Laravel caches"
./vendor/bin/sail artisan optimize:clear

step "Start the scheduler"
./vendor/bin/sail exec -T laravel.test sh -lc \
    "if ! pgrep -f '[a]rtisan schedule:work' >/dev/null; then nohup php artisan schedule:work >> storage/logs/scheduler.log 2>&1 & fi"

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
