# ADMS (Attendance Device Management System)

ADMS is a comprehensive Attendance Device Management System designed to handle biometric and access control data from various devices. This system is built using Laravel, a PHP framework, provides functionalities to store, manage user and fingerprint data.

## Features

- Fingerprint data storage
- Device status monitoring

## Screenshots
Device Connected
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_7.png)
Attendance Recorded
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_8.png)
Device Log
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_9.png)
Attendence Log
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_10.png)

## Installation

### Prerequisites

Before you begin, ensure you have the following installed on your system:

- PHP >= 8.0
- Composer
- MySQL or any other supported database
- Web server (Apache, Nginx, etc.)

### Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/saifulcoder/adms-server-ZKTeco.git adms-server
   cd adms-server
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Copy the `.env` file**
   ```bash
   cp .env.example .env
   ```

4. **Generate application key**
   ```bash
   php artisan key:generate
   ```

5. **Configure the `.env` file**
   Open the `.env` file and set your database credentials and other environment variables:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=adms
   DB_USERNAME=root
   DB_PASSWORD=
   ```

6. **Run the migrations**
   ```bash
   php artisan migrate
   ```

7. **Serve the application**
   ```bash
   php artisan serve
   ```

### Monitoring Device Status

You can monitor the status of devices by querying the `devices` table where the `online` field indicates the last time the device was online.

## Docker Permissions on Ubuntu

If `docker ps` reports `permission denied` while `sudo docker ps` works, Docker is running but your user is not allowed to access the Docker socket. Run:

```bash
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
newgrp docker
id -nG
docker ps
```

The `id -nG` output should include `docker`. If it does not, completely log out of Ubuntu and log back in, then run `docker ps` again. Do not run `./scripts/install.sh`, `./scripts/update.sh`, or `./scripts/restart.sh` with `sudo`, because that can create root-owned project files.

## Docker Compose v2 Is Required

Sail uses the `docker compose` plugin when it is present and silently falls back
to the standalone `docker-compose` binary when it is not. That binary is Compose
v1, retired in 2023, and it fails against current Docker Engine releases with a
Python traceback ending in:

```
KeyError: 'ContainerConfig'
```

Check which one is installed:

```bash
docker compose version
```

If that reports "is not a docker command", install the plugin:

```bash
sudo apt-get install -y docker-compose-plugin
```

Ubuntu's own `docker.io` package does not carry the plugin. When apt cannot find
it, install the binary directly:

```bash
sudo mkdir -p /usr/local/lib/docker/cli-plugins
sudo curl -fsSL \
    "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-$(uname -m)" \
    -o /usr/local/lib/docker/cli-plugins/docker-compose
sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
docker compose version
```

Compose v1 and v2 name containers differently (`project_service_1` against
`project-service-1`), so the first run after switching creates new containers and
leaves the old ones stopped. The database lives in the named `sail-mysql` volume
rather than inside the container, so its data carries over — confirm the
dashboard still shows attendance rows before clearing the old containers with
`docker container prune`.

`install.sh` and `update.sh` both check for the plugin before doing any work and
stop with these instructions rather than the traceback.

## Updating an Existing Sail Installation

From the project root, run:

```bash
./scripts/update.sh
```

The script pulls fast-forward repository changes, installs PHP dependencies, rebuilds and restarts Sail, waits for MySQL to report healthy, runs migrations, clears Laravel caches, rebuilds frontend assets when their package files changed, starts the scheduler, and prints the final container and migration status. It does not modify `.env` or delete the database volume. It stops before pulling if tracked local changes are present.

Recreated containers take time to come back: MySQL accepts connections some
seconds after its container starts, and InnoDB recovery on a large punch table
can stretch that to a minute. The script waits on the container healthcheck
before migrating, so a slow database no longer surfaces as
`SQLSTATE[HY000] [2002] Connection refused`.

To restart the application after changing `.env` values or restarting Docker containers, run:

```bash
./scripts/restart.sh
```

For Composer, npm, Dockerfile, or repository updates, use `./scripts/update.sh` so dependencies and migrations are also handled.

## Installing From a Fresh Checkout

After Docker is installed, run this from the project root:

```bash
./scripts/install.sh
```

The script creates `.env` only when it does not already exist, installs dependencies, builds the frontend, starts Sail, generates the Laravel key, runs migrations, starts the scheduler, and checks the installation. It never overwrites an existing `.env` or deletes the database volume. Payroll credentials still need to be entered in `.env` before the initial payroll sync.

## Postman Collection

For testing and interacting with the API endpoints, you can use the provided Postman collection:
[Postman Collection](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/ADMS server ZKTeco.postman_collection.json)


## Authors

- [@saifulcoder](https://github.com/saifulcoder)

## For Improvement and project

contact us saiful.coder@gmail.com

## Contributing

This project helps you and you want to help keep it going? Buy me a coffee:
<br> <a href="https://www.buymeacoffee.com/saifulcoder" target="_blank"><img src="https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png" alt="Buy Me A Coffee" style="height: 61px !important;width: 174px !important;box-shadow: 0px 3px 2px 0px rgba(190, 190, 190, 0.5) !important;" ></a><br>
or via <br>
<a href="https://saweria.co/saifulcoder">https://saweria.co/saifulcoder</a>

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
