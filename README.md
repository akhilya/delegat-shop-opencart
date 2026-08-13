# Delegat OpenCart shop

Standalone OpenCart **4.0.2.3** stack with a vendored **Delegat Bridge** (v **1.2.0**). Use it to smoke-test the Delegat OpenCart adapter locally or on Dokploy.

This repo does **not** depend on the Control Plane. Extension files live in `extension/delegat_bridge/` and are copied into the image at build time.

Apache is configured with `CGIPassAuth On` so `Authorization: Bearer` reaches PHP (required by the Delegat adapter).

CI publishes two artifacts:

| Artifact | What it is | How to get it |
| --- | --- | --- |
| Shop image | OpenCart + Delegat Bridge, ready to run with the compose `db` service | `ghcr.io/akhilya/delegat-shop-opencart` (`latest` on `main`, semver on `v*` tags) |
| Bridge zip | Installer for an **existing** OpenCart 4 shop | GitHub Release asset `delegat-opencart-bridge.ocmod.zip` on each `v*` tag |

## One local command

```bash
docker compose up -d --build
```

`--build` is only required the first time (or after Dockerfile / extension changes). To use a published image instead (needs `docker login ghcr.io` for this private package):

```bash
docker compose pull
docker compose up -d
```

`.env` is optional. Compose defaults match the table below. To change URL, ports, or passwords:

```bash
cp .env.example .env
# edit .env
docker compose up -d
```

First image build downloads OpenCart 4.0.2.3. Watch:

```bash
docker compose logs -f opencart
```

When you see `READY`, open http://localhost:8089

Stop (keep data): `docker compose down`  
Wipe volumes: `docker compose down -v`

## Environment

| Variable | Default | Notes |
| --- | --- | --- |
| `SITE_URL` | `http://localhost:8089/` | Public origin **with trailing slash**. Written into `config.php` / `admin/config.php` on **every** start. |
| `PUBLISH_PORT` | `8089` | Host → container **80**. On Dokploy leave a high unused port; Traefik still targets container 80. Do **not** publish host `:80`. |
| `SHOP_IMAGE_TAG` | `latest` | Tag of `ghcr.io/akhilya/delegat-shop-opencart`. |
| `MYSQL_*` | `opencart` | MariaDB database, user, passwords. |
| `OPENCART_USERNAME` / `OPENCART_PASSWORD` / `OPENCART_EMAIL` | `admin` / `admin` / `admin@example.com` | CLI install (first boot only). |
| `BRIDGE_API_KEY` | `delegat-oc-sandbox-api-key-local` | Delegat Bridge API key. Paste into Delegat. |

## Credentials (paste into Delegat)

| | Local default |
| --- | --- |
| Storefront | http://localhost:8089 |
| Admin | http://localhost:8089/admin |
| Login | `admin` / `admin` |
| Bridge URL | `http://localhost:8089/index.php?route=extension/delegat_bridge/api/delegat` |
| API key | `delegat-oc-sandbox-api-key-local` |

If Delegat.Module runs **inside Docker**, use `http://host.docker.internal:8089/index.php?route=extension/delegat_bridge/api/delegat` instead of `localhost`.

## Health

```bash
curl -sS -H "Authorization: Bearer delegat-oc-sandbox-api-key-local" \
  "http://localhost:8089/index.php?route=extension/delegat_bridge/api/delegat&action=health"
```

Expect JSON. The bridge accepts `Authorization: Bearer` (what the Delegat adapter sends) and `X-Delegat-Api-Key`.

## Install the bridge on an existing OpenCart 4 shop

1. Download `delegat-opencart-bridge.ocmod.zip` from [Releases](https://github.com/akhilya/delegat-shop-opencart/releases).
2. In OpenCart admin: **Extensions → Installer** — upload the zip and install.
3. **Extensions → Extensions → Modules** — install **Delegat Bridge**, enable it, set a long random API key, save.
4. In Delegat, paste:
   `https://your-shop/index.php?route=extension/delegat_bridge/api/delegat`

Pack a zip locally: `python scripts/pack-bridge.py` → `dist/delegat-opencart-bridge.ocmod.zip`.

## Publish (maintainers)

- Push to `main` → shop image `ghcr.io/akhilya/delegat-shop-opencart:latest` (and `sha-…`).
- Push tag `v1.2.0` → image `v1.2.0` **and** a GitHub Release with the bridge zip.

```bash
git tag v1.2.0
git push origin v1.2.0
```

## Dokploy

Same compose file. Do **not** bake Traefik labels or `dokploy-network` into compose — Dokploy attaches those when you add a domain.

1. Connect this **private** GitHub repo (GitHub App or deploy key).
2. Create a Compose application; compose file `docker-compose.yml`.
3. Environment = copy of `.env.example` with real values, especially:
   - `SITE_URL=https://oc.your.domain/` (**trailing slash**)
   - Strong `MYSQL_*` / `OPENCART_PASSWORD` / `BRIDGE_API_KEY`
   - `PUBLISH_PORT` = a high unused port (not `80`)
   - Optional: `SHOP_IMAGE_TAG=v1.2.0` to pin the GHCR image (Dokploy needs a GHCR pull credential for this private package). Git compose can also **build** the Dockerfile.
4. **Domains:** hostname → service `opencart`, container port **80**, HTTPS / Let’s Encrypt.
5. Deploy.

Changing `SITE_URL` after data exists: the entrypoint rewrites `HTTP_SERVER` / `HTTPS_SERVER` (and admin catalog URLs) from `SITE_URL` on every start. If assets still break, `docker compose down -v` and redeploy.

Bridge URL on Dokploy: `https://oc.your.domain/index.php?route=extension/delegat_bridge/api/delegat`
