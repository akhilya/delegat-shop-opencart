# Магазин Delegat + OpenCart

[English](README.md) · [Русский](README.ru.md)

В этом репозитории две вещи:

1. **Тестовый магазин** — OpenCart 4 + мост Delegat, запускается через Docker.
2. **Zip расширения** — если у вас уже есть свой OpenCart 4.

Нужен только [Docker Desktop](https://www.docker.com/products/docker-desktop/), и он должен быть запущен.

---

## А. Тестовый магазин на компьютере

В папке репозитория:

```bash
docker compose up -d --build
```

`--build` нужен в первый раз (и после правок файлов магазина). Первая сборка может занять несколько минут. Смотреть прогресс:

```bash
docker compose logs -f opencart
```

Когда появится `READY`, откройте:

| | Адрес |
| --- | --- |
| Магазин | http://localhost:8089 |
| Админка | http://localhost:8089/admin |
| Логин | `admin` / `admin` |

### Что вставить в Delegat

| Поле | Значение |
| --- | --- |
| URL моста | `http://localhost:8089/index.php?route=extension/delegat_bridge/api/delegat` |
| API-ключ | `delegat-oc-sandbox-api-key-local` |

Если сам Delegat тоже в Docker, вместо `localhost` укажите `http://host.docker.internal:8089/index.php?route=extension/delegat_bridge/api/delegat`.

**Остановить** (данные магазина сохранятся): `docker compose down`  
**Удалить всё** и начать заново: `docker compose down -v`

### Сменить порт, пароль или ключ

```bash
cp .env.example .env
```

Поправьте `.env` и снова `docker compose up -d`. Для значений по умолчанию файл `.env` не нужен.

У OpenCart в `SITE_URL` **обязателен слэш в конце**, например `http://localhost:8089/`.

---

## Б. Мост на уже существующий магазин

1. Скачайте **`delegat-opencart-bridge.ocmod.zip`** со страницы [Releases](https://github.com/akhilya/delegat-shop-opencart/releases).
2. В админке OpenCart: **Extensions → Installer** — загрузите zip и установите.
3. **Extensions → Extensions → Modules** — установите **Delegat Bridge**, включите (**On**), задайте длинный случайный API-ключ, сохраните.
4. В Delegat вставьте:
   - URL: `https://ваш-магазин/index.php?route=extension/delegat_bridge/api/delegat`
   - тот же API-ключ

---

## В. Выложить тестовый магазин на сервер (Dokploy)

Тот же `docker-compose.yml`. В Dokploy нужны только: Git-репозиторий, переменные окружения и домен. Не добавляйте в compose свои настройки Traefik или сети.

1. Подключите этот приватный GitHub-репозиторий.
2. Новое приложение **Compose**, файл `docker-compose.yml`.
3. Environment — скопируйте `.env.example` и поменяйте хотя бы:
   - `SITE_URL=https://oc.ваш.домен/` — **со слэшем в конце**
   - нормальные `OPENCART_PASSWORD` и `BRIDGE_API_KEY`
   - `PUBLISH_PORT` — любой большой порт, **не** `80` (80 уже занят Dokploy)
4. **Domains:** ваш хост → сервис **`opencart`**, порт контейнера **`80`**, включите HTTPS.
5. Deploy.

В Delegat тогда будет `https://oc.ваш.домен/index.php?route=extension/delegat_bridge/api/delegat`.

Если после смены `SITE_URL` магазин «поехал», сбросьте данные и задеплойте снова.

---

## Если не запускается

- Подождите: первая сборка долгая.
- Проверьте, что Docker Desktop запущен.
- Логи: `docker compose logs -f opencart`
- Сброс: `docker compose down -v`, затем `docker compose up -d --build`

Проверка, что мост отвечает:

```bash
curl -sS -H "Authorization: Bearer delegat-oc-sandbox-api-key-local" \
  "http://localhost:8089/index.php?route=extension/delegat_bridge/api/delegat&action=health"
```

Должен вернуться JSON, а не пустая страница или ошибка HTML.

---

## Для тех, кто сопровождает репозиторий

Пуш в `main` публикует образ магазина `ghcr.io/akhilya/delegat-shop-opencart:latest`.

Тег `v1.2.0` публикует этот тег образа **и** GitHub Release с `delegat-opencart-bridge.ocmod.zip`.

```bash
git tag v1.2.0
git push origin v1.2.0
```

Образ приватный, как и репозиторий. Для `docker pull` нужен `docker login ghcr.io`.
