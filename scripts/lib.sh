#!/usr/bin/env bash

# Shared helpers for install.sh and update.sh. Sourced, never executed.
#
# Both scripts bring the stack up and then immediately migrate, so both hit the
# same two failure modes — a missing Compose plugin and a database that has not
# finished booting. Keeping the guards here stops the second copy from drifting
# out of step with the first.

# Prefix for fail(); each script sets its own before sourcing this file.
: "${SCRIPT_LABEL:=Stopped}"

step() {
    printf '\n==> %s\n' "$1"
}

fail() {
    printf '\n%s: %s\n' "$SCRIPT_LABEL" "$1" >&2
    exit 1
}

# Sail silently falls back to the standalone `docker-compose` binary when the
# Compose plugin is absent. That binary is Compose v1, retired in 2023, and it
# dies with a Python KeyError on 'ContainerConfig' against any Docker Engine new
# enough to have dropped that field from image inspect — mid-run, after the git
# pull, with a traceback that points at nothing in this repo.
require_compose_v2() {
    docker compose version >/dev/null 2>&1 && return 0

    cat >&2 <<'EOF'

Docker Compose v2 is not installed. Sail would fall back to the retired
docker-compose v1, which crashes on modern Docker with:

    KeyError: 'ContainerConfig'

Install the plugin:

    sudo apt-get install -y docker-compose-plugin

Or, if that package is not available on this distribution:

    sudo mkdir -p /usr/local/lib/docker/cli-plugins
    sudo curl -fsSL \
        "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-$(uname -m)" \
        -o /usr/local/lib/docker/cli-plugins/docker-compose
    sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

Then confirm with: docker compose version
EOF

    fail "Docker Compose v2 is required."
}

# MySQL accepts connections some seconds after its container starts, and a
# recreated container starts over: InnoDB recovery on a large punch table, or a
# first-run initialisation, can take a minute. Migrations that arrive early die
# with "SQLSTATE[HY000] [2002] Connection refused" under forty frames of vendor
# stack trace, which reads like a broken migration rather than a boot race — so
# wait on the container's own healthcheck rather than guessing with sleep.
wait_for_database() {
    local container status

    container="$(./vendor/bin/sail ps -q mysql 2>/dev/null || true)"
    [[ -n "$container" ]] || fail "The mysql container is not running. Check './vendor/bin/sail ps'."

    for _ in $(seq 1 90); do
        status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container" 2>/dev/null || echo unknown)"

        case "$status" in
            # No healthcheck defined: nothing to wait on, let migrate speak.
            healthy | none) return 0 ;;
        esac

        sleep 2
    done

    printf '\nLast lines from MySQL:\n' >&2
    ./vendor/bin/sail logs --tail=20 mysql >&2 || true
    fail "MySQL did not become healthy within three minutes."
}
