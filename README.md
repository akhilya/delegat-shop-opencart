# Delegat OpenCart shop

[English](README.md) · [Русский](README.ru.md)

Two things in this repo:

1. **A test shop** — OpenCart 4 + Delegat Bridge, started with Docker.
2. **An extension zip** — for an OpenCart 4 shop you already have.

You only need [Docker Desktop](https://www.docker.com/products/docker-desktop/) (must be running).

---

## A. Run a test shop on your computer

In this folder:

```bash
docker compose up -d --build
```

`--build` is needed the first time (and after you change the shop files). The first build can take a few minutes. Watch progress:

```bash
docker compose logs -f opencart
```

When you see `READY`, open:

| | Address |
| --- | --- |
| Shop | http://localhost:8089 |
| Admin | http://localhost:8089/admin |
| Login | `admin` / `admin` |

### Connect it in Delegat

| Field | Value |
| --- | --- |
| Bridge URL | `http://localhost:8089/index.php?route=extension/delegat_bridge/api/delegat` |
| API key | `delegat-oc-sandbox-api-key-local` |

If Delegat itself runs in Docker, use `http://host.docker.internal:8089/index.php?route=extension/delegat_bridge/api/delegat` instead of `localhost`.

**Stop** (keep the shop data): `docker compose down`  
**Delete everything** and start over: `docker compose down -v`

### Change the port, password, or key

```bash
cp .env.example .env
```

Edit `.env`, then `docker compose up -d` again. You do **not** need a `.env` file for the defaults above.

On OpenCart, `SITE_URL` **must end with a slash**, for example `http://localhost:8089/`.

---

## B. Add Delegat to a shop you already have

1. Download **`delegat-opencart-bridge.ocmod.zip`** from [Releases](https://github.com/akhilya/delegat-shop-opencart/releases).
2. In OpenCart admin: **Extensions → Installer** — upload the zip and install.
3. **Extensions → Extensions → Modules** — install **Delegat Bridge**, turn it **On**, set a long random API key, save.
4. In Delegat, paste:
   - URL: `https://your-shop/index.php?route=extension/delegat_bridge/api/delegat`
   - the same API key

---

## C. Put the test shop on a server (Dokploy)

Same `docker-compose.yml`. In Dokploy you only need: the Git repo, environment variables, and a domain. Do not add extra Traefik / network settings in the compose file.

1. Connect this GitHub repo.
2. New **Compose** app, file `docker-compose.yml`.
3. Environment — copy `.env.example` and change at least:
   - `SITE_URL=https://oc.your.domain/` — **slash at the end**
   - a real `OPENCART_PASSWORD` and `BRIDGE_API_KEY`
   - `PUBLISH_PORT` — any high port, **not** `80` (Dokploy already uses 80)
4. **Domains:** your hostname → service **`opencart`**, container port **`80`**, enable HTTPS.
5. Deploy.

Then in Delegat use `https://oc.your.domain/index.php?route=extension/delegat_bridge/api/delegat`.

If the shop looks broken after you change `SITE_URL`, reset data and deploy again.

---

## If it does not start

- Wait: the first build is slow.
- Confirm Docker Desktop is running.
- Check logs: `docker compose logs -f opencart`
- Reset: `docker compose down -v` then `docker compose up -d --build`

Quick check that the bridge answers:

```bash
curl -sS -H "Authorization: Bearer delegat-oc-sandbox-api-key-local" \
  "http://localhost:8089/index.php?route=extension/delegat_bridge/api/delegat&action=health"
```

You should get JSON (not an empty page or HTML error).

---

## For maintainers

Push to `main` publishes the shop image `ghcr.io/akhilya/delegat-shop-opencart:latest`.

A tag `v1.2.0` also publishes that image tag **and** a GitHub Release with `delegat-opencart-bridge.ocmod.zip`.

```bash
git tag v1.2.0
git push origin v1.2.0
```

Anyone can pull `ghcr.io/akhilya/delegat-shop-opencart:latest` without logging in.
