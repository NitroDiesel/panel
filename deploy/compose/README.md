# Hardened Docker Compose stack

Runs the panel, MariaDB 11.4 and Redis 7 with no secrets in git and nothing published except the panel's HTTP port,
bound to `127.0.0.1`. TLS is handled by a reverse proxy you run in front of it.

## Setup

```sh
cd deploy/compose
./init.sh                 # writes .env (generated passwords) and panel.env (settings)
$EDITOR panel.env         # set APP_URL, TRUSTED_PROXIES and mail
docker compose up -d --build
docker compose exec panel php artisan p:user:make
```

The first start runs migrations and seeds the eggs; all three services report `healthy` within about a minute.

`.env` and `panel.env` are ignored by git and created with mode `0600`. Keep a copy of both somewhere safe, along with
the panel's own key file (see [Backups](#backups)).

| Setting (in `.env`) | Default     | Purpose                                        |
| ------------------- | ----------- | ---------------------------------------------- |
| `PANEL_BIND`        | `127.0.0.1` | Host address the panel's port is published on  |
| `PANEL_PORT`        | `8080`      | Host port the panel is published on            |

## Reverse proxy

Point your proxy at `http://127.0.0.1:8080` (or your `PANEL_PORT`) and set `APP_URL` to the public `https://` URL.
The panel only trusts `X-Forwarded-*` headers from the addresses in `TRUSTED_PROXIES`. With the proxy on the same
host, connections arrive from the `frontend` network's gateway:

```sh
docker network inspect pterodactyl_frontend -f '{{(index .IPAM.Config 0).Gateway}}'
```

Put that address in `TRUSTED_PROXIES` in `panel.env`, then `docker compose up -d` to apply it.

**Caddy**

```
panel.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

**nginx**

```nginx
server {
    listen 443 ssl http2;
    server_name panel.example.com;
    # ssl_certificate ...; ssl_certificate_key ...;
    client_max_body_size 100m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**Traefik** (running as a container): attach it to the `pterodactyl_frontend` network, add labels to the `panel`
service in a `compose.override.yml`, and set `TRUSTED_PROXIES` to Traefik's address on that network.

```yaml
services:
  panel:
    labels:
      traefik.enable: "true"
      traefik.http.routers.panel.rule: Host(`panel.example.com`)
      traefik.http.routers.panel.tls.certresolver: letsencrypt
      traefik.http.services.panel.loadbalancer.server.port: "8080"
```

## Upgrades

```sh
git pull
docker compose up -d --build
```

The panel runs `migrate --seed --force` on every start. To run migrations by hand instead, set
`APP_AUTO_MIGRATE=false` in `panel.env` and run `docker compose exec panel php artisan migrate --seed --force`.
MariaDB upgrades its system tables automatically (`MARIADB_AUTO_UPGRADE`). Image digests are pinned in `compose.yml`;
update them deliberately.

## Backups

Three things are needed to restore a panel: the database, the panel's key file (`/app/var/.env`, which holds
`APP_KEY`; without it, stored secrets such as node tokens and 2FA secrets can't be decrypted), and your `.env` and
`panel.env`.

```sh
docker compose exec -T database sh -c 'exec mariadb-dump --single-transaction -upterodactyl -p"$MARIADB_PASSWORD" panel' > panel.sql
docker compose cp panel:/app/var/.env panel-var.env
```

Restore into a running stack:

```sh
docker compose exec -T database sh -c 'exec mariadb -upterodactyl -p"$MARIADB_PASSWORD" panel' < panel.sql
```

To restore the key file into a fresh `panel-var` volume, copy it in before the panel's first start:

```sh
docker compose up -d database cache
docker run --rm -v pterodactyl_panel-var:/var -v "$PWD/panel-var.env:/backup.env:ro" alpine \
  sh -c 'cp /backup.env /var/.env && chown 100:101 /var/.env'
docker compose up -d
```

## Running Wings

Wings manages game server containers through the host's Docker socket, so run it on the host (or as its own
container with `/var/run/docker.sock` and `/var/lib/pterodactyl` mounted), never inside this stack and never on the
`backend` network. Nodes connect to the panel through its public URL.

## How the stack is hardened

- `database` and `cache` are only on the `backend` network, which is `internal`: they publish no ports and have no
  route out of the host. Only `panel` joins both networks.
- Every service runs as a non-root user with a read-only root filesystem, `no-new-privileges`, all capabilities
  dropped, and memory, CPU and PID limits. Writable paths are named volumes plus small `tmpfs` mounts.
- Redis requires a password, and its default user may not run `FLUSHALL`, `CONFIG`, `DEBUG` or `MODULE`. It keeps an
  append-only file, so sessions and queued jobs survive restarts.
- MariaDB's root account only accepts connections from inside its container.
- The panel gives up on an unreachable database after 2 seconds (`DB_CONNECT_TIMEOUT`), so requests and health
  checks fail fast instead of tying up PHP workers.
- Logs rotate at 10 MB, keeping three files per service.

Check a running stack against all of the above with `./verify.sh` (use `DOCKER="sudo docker" ./verify.sh` if your
user can't reach the Docker daemon). It exits non-zero if any check fails.

## Troubleshooting

- `php artisan tinker` needs a writable home directory: `docker compose exec -e HOME=/tmp panel php artisan tinker`.
- If a service never becomes healthy, `docker compose logs <service>` shows why. The panel waits for the database and
  cache to be healthy before it starts.
