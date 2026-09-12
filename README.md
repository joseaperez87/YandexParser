# Yandex Parser — интеграция с Яндекс.Картами (delivery-пак)

Небольшое приложение из двух экранов: подключение карточки организации на
Яндекс.Картах (ссылка + валидация) и вывод её данных — отзывов (до ~600),
среднего рейтинга, числа оценок и числа отзывов. Парсинг вынесен в отдельный
сервис, выполняется в очереди и устойчив к антиботу и смене разметки.

Это **готовый пакет сдачи**: чистый экспорт приложения в `app/`, рабочий
`docker-compose.yml` и production-пример окружения. Git-истории внутри пака
нет — она живёт в репозитории разработки (`backend/.git`).

```
delivery/
├── app/                      # приложение (чистый экспорт backend/: Laravel 12 + Vue 3 SPA)
├── docker-compose.yml        # основной путь запуска: app + web + db + worker (+ headless-профиль)
├── docker/php/Dockerfile     # php 8.4-fpm + composer + node 20 (сборка SPA внутри образа)
├── docker/nginx/default.conf # nginx → app:9000, корень /var/www/public
├── .env.production.example   # шаблон production-окружения (скопировать в app/.env)
└── README.md                 # этот файл
```

## Возможности

- Авторизация логин/пароль одним сид-пользователем (Sanctum, SPA cookie-based).
- Экран «Настройки»: вставка ссылки Яндекс.Карт, валидация (включая короткие
  ссылки), сохранение и запуск парсинга; статус и прогресс с автопolling (~3s).
- Экран «Отзывы»: карточка организации + агрегаты (рейтинг, оценки, отзывы —
  раздельно), список отзывов (автор, дата, оценка, текст), пагинация 50/стр
  без перезагрузки; список блокируется, пока идёт парсинг.
- Parser: внутренний JSON-endpoint карточки (основная стратегия) + headless
  Playwright (fallback), троттлинг+jitter, ретраи/бэкофф, ротация User-Agent.
- Очередь: фоновый `ParseOrganizationJob`, прогресс, ретраи, идемпотентный
  upsert, снимки агрегатов и история «было → стало».

## Стек

| Слой | Технология |
|---|---|
| Язык / фреймворк | PHP 8.4 / Laravel 12 |
| БД | MySQL 8 |
| Очередь | Laravel Queue, драйвер `database` |
| Аутентификация | Laravel Sanctum (SPA, cookie-based) |
| HTTP-клиент | Guzzle (`Illuminate\Support\Facades\Http`) |
| Разбор HTML | `symfony/dom-crawler` |
| Headless (fallback) | Node.js + Playwright (через `Symfony Process`) |
| Frontend | Vue 3 (Composition API) + Vite + Pinia + Vue Router + Axios |
| Стили | Tailwind CSS 4 |
| Тесты | Pest 3 |
| Доставка | docker-compose (app + nginx + MySQL 8 + worker) |

## Требования

- Docker + docker-compose (основной путь), **или**
- PHP 8.2+ (проверено на 8.4), Composer 2, Node.js 20+, MySQL 8 (путь WAMP).
- **Настроенный CA bundle для HTTPS** (иначе cURL падает с
  `cURL error 60: SSL certificate problem`). В `php.ini` должны быть заданы
  `curl.cainfo` и `openssl.cafile` (путь к `cacert.pem`); в docker-образе
  системный CA уже есть. Если настроить нельзя, можно для локальной разработки
  указать `YANDEX_MAPS_VERIFY_SSL=false` или путь к CA-бандлу в `.env`.

## Быстрый старт (docker-compose, основной путь)

Все команды — из каталога `delivery/`:

```bash
cd delivery

# 1. Окружение: скопировать шаблон и заполнить (ключ, БД, домен, seed-доступ)
cp .env.production.example app/.env
# Для локального compose достаточно поменять:
#   DB_HOST=db, DB_PASSWORD=<любой>, APP_KEY — сгенерируем ниже,
#   APP_URL=http://localhost:8080, SANCTUM_STATEFUL_DOMAINS=localhost:8080

# 2. Собрать и поднять: app (php-fpm) + web (nginx :8080) + db (MySQL 8) + worker (очередь)
docker compose up --build -d

# 3. Первый запуск внутри контейнера
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# 4. Открыть http://localhost:8080 и войти seed-пользователем
#    (SEED_USER_EMAIL / SEED_USER_PASSWORD из app/.env)
```

Что уже сделано за вас образом `app`: `composer install --no-dev`,
`npm ci && npm run build` (ассеты SPA в `public/build`).

> `worker` (`php artisan queue:work --tries=3`) поднимается сам и работает
> постоянно — иначе задача парсинга не выполнится и статус останется
> «в очереди». **Признак проблемы:** статус долго «в очереди», а прогресс не
> растёт → смотрите `docker compose logs worker`.

Полезное:

```bash
docker compose logs -f app worker      # логи приложения и очереди
docker compose exec app php artisan queue:failed   # упавшие задачи
docker compose exec app php artisan queue:retry all
docker compose --profile headless up --build -d    # профиль Playwright-fallback
```

## Альтернатива: запуск без docker (WAMP, из `app/`)

```bash
cd delivery/app

composer install
# Windows: Copy-Item .env.example .env
cp .env.example .env
php artisan key:generate

# БД должна существовать (MySQL запущена). В клиенте MySQL:
#   CREATE DATABASE yandexparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate --seed

php artisan serve            # http://localhost:8000
php artisan queue:work       # отдельный терминал, обязателен для парсинга

npm install
npm run dev                  # или: npm run build
```

Демо-доступ задаётся в `app/.env`:

- `SEED_USER_EMAIL` (по умолчанию `admin@example.com`)
- `SEED_USER_PASSWORD` (по умолчанию `password`)

## Очередь (фоновый парсинг)

Парсинг выполняется в фоне через очередь (`QUEUE_CONNECTION=database`). Пока
воркер не запущен, задачи лежат в таблице `jobs`, а статус организации остаётся
«в очереди» и прогресс не растёт — в compose за это отвечает сервис `worker`,
в WAMP — отдельное окно `php artisan queue:work`.

Прогресс виден в UI: автопolling `GET /api/organization/parse-status` каждые
~3s, пока `parse_status = queued|running` (composable `useParsePolling` + блок
`ParseProgress`), плюс ручная кнопка обновления. При зависшем `queued` UI
подсказывает проверить `queue:work`.

## Переменные окружения

| Переменная | Значение по умолчанию | Назначение |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` (в `.env.production.example`: `production` / `false`) | окружение и отладка |
| `APP_URL` | `http://localhost:8000` (compose: `http://localhost:8080`) | базовый URL приложения |
| `DB_CONNECTION` | `mysql` | драйвер БД |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` (compose: `db` / `3306`) | сервер MySQL |
| `DB_DATABASE` | `yandexparser` | имя БД |
| `DB_USERNAME` / `DB_PASSWORD` | `root` / — | доступ к БД |
| `SESSION_DRIVER` | `database` | сессии в БД |
| `QUEUE_CONNECTION` | `database` | очередь в БД (без Redis) |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,…` | домены SPA-аутентификации |
| `SESSION_DOMAIN` | `.yandexparser.local` | домен cookie |
| `SEED_USER_NAME` | `Admin` | имя сид-пользователя |
| `SEED_USER_EMAIL` | `admin@example.com` | логин демо-доступа |
| `SEED_USER_PASSWORD` | `password` | пароль демо-доступа |
| `YANDEX_MAPS_STRATEGY` | `json` | стратегия: `json` или `headless` |
| `YANDEX_MAPS_REGION` | `ru` | регион/язык (`Accept-Language`) |
| `YANDEX_MAPS_TIMEOUT` | `15` | таймаут HTTP-запросов, сек |
| `YANDEX_MAPS_VERIFY_SSL` | `true` | `true` / `false` / путь к CA-бандлу |
| `YANDEX_MAPS_MAX_REVIEWS` | `600` | максимум собираемых отзывов |
| `YANDEX_MAPS_THROTTLE_MS` | `800` | базовая пауза между запросами страниц |
| `YANDEX_MAPS_THROTTLE_JITTER_MS` | `400` | случайная добавка к паузе (jitter) |
| `YANDEX_MAPS_HEADLESS_ENABLED` | `false` | включить fallback Playwright |
| `YANDEX_MAPS_REVIEWS_ENDPOINT` | `/maps/api/business/fetchReviews` | внутренний endpoint |
| `YANDEX_MAPS_LOCALE` | `ru_RU` | локаль внутреннего API |
| `YANDEX_MAPS_RANKING` | `by_relevance_org` | сортировка отзывов |
| `YANDEX_MAPS_MAX_PAGES` | `12` | максимум страниц (как в UI, ~600 отзывов) |
| `YANDEX_MAPS_RETRIES` | `3` | повторы при блокировке |
| `YANDEX_MAPS_BACKOFF_BASE_MS` | `1000` | база экспоненциального бэкоффа |

## Архитектура (`app/`)

```
app/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/     # Auth, Organization, Review
│   │   ├── Requests/            # Login, StoreOrganization, IndexReviews
│   │   └── Resources/           # Organization, Review, ParseRun
│   ├── Models/                  # User, Organization, Review, ParseRun,
│   │                            # OrganizationSnapshot, ParseChange
│   ├── Jobs/ParseOrganizationJob.php
│   ├── Support/AntiBot/         # Throttle, UserAgentRotator, ProxyPool
│   └── Services/YandexMaps/
│       ├── Contracts/           # ParserStrategy, YandexMapsParser (фасад)
│       ├── Strategies/          # JsonApiStrategy, HeadlessStrategy
│       ├── Support/             # ReviewNormalizer, CardMetadata
│       ├── UrlResolver.php      # нормализация URL, короткие ссылки, business_id
│       ├── ParserResult.php     # VO результата
│       ├── ParseContext.php
│       └── Exceptions/          # SourceChanged, Blocked, EmptyResponse, Parsing
├── database/migrations/
└── resources/js/                # Vue 3 SPA (router, stores, views)
```

Контроллеры только валидируют вход и вызывают сервисы/джобы; HTTP-логики
парсинга в них нет. Весь доступ к внешнему источнику инкапсулирован в
`Services/YandexMaps`.

## Подход к парсингу

### Основная стратегия — внутренний JSON карточки

1. `UrlResolver` нормализует URL, при короткой ссылке (`/maps/-/…`) следует по
   редиректу, извлекает `business_id`.
2. GET канонической карточки с «человеческими» заголовками; сохраняются cookies.
3. Из HTML достаётся `csrfToken` (meta/regex) и метаданные (`title`,
   `address`) через `CardMetadata` (`og:title`/JSON-LD/`itemprop`).
4. Запросы к `/maps/api/business/fetchReviews` с `businessId`, `page`,
   `pageSize=50`, `reqId`, `ranking`, `locale=ru_RU`, `sessionId`, `ajax=1`,
   `csrfToken` и подписью `s` (хеш `djb2` по отсортированному query); cookies
   карточки переиспользуются. Ответ — `{ data: { reviews, params } }`; при
   возврате только `csrfToken` — один повтор с новым токеном.
5. Пагинация до `reviewsCount`, лимита `MAX_REVIEWS` (~600) или до пустой
   страницы/дубликатов.
6. Сборка результата: `reviews[]`, `rating`, `ratingsCount`, `reviewsCount`,
   `title`, `address`.

### Резервная стратегия — headless (Playwright)

`HeadlessStrategy` запускает Node-скрипт `resources/headless/fetch.mjs`:
Playwright открывает карточку, эмулирует прокрутку и перехватывает ответы
внутреннего endpoint'а, возвращая тот же `ParserResult`. Включается флагом
`YANDEX_MAPS_HEADLESS_ENABLED` и/или как автоматический fallback при смене
схемы (`SourceChangedException`) или блокировке. В compose для него есть
профиль `headless`.

### Сравнение стратегий

| Критерий | Внутренний JSON | Headless (Playwright) |
|---|---|---|
| Скорость | высокая (десятки мелких запросов) | низкая (рендер + скролл) |
| Ресурсы | минимальные | браузер + память |
| Хрупкость к токенам | средняя (`csrfToken`, cookies) | низкая (браузер сам проходит) |
| Хрупкость к разметке | средняя (схема JSON) | выше (DOM меняется) |
| Обнаружение бота | возможно (403/captcha) | реже (реальный браузер) |
| Масштаб (50 филиалов) | хорошо | плохо |

**Выбор:** JSON как основной (скорость и масштаб), headless — как fallback.
Риски JSON (смена схемы/токенов) закрываются валидацией схемы, статусами и
переключением на headless.

## Обход защиты (анти-бан)

Что реализовано:

- **Троттлинг.** Пауза `YANDEX_MAPS_THROTTLE_MS` (+ jitter
  `YANDEX_MAPS_THROTTLE_JITTER_MS`) между страницами.
- **Бэкофф.** При `BlockedException` (403/captcha) — экспоненциальная задержка
  и повтор в `YandexMapsParser`; сама задача имеет `tries=3` и бэкофф `[10,60,300]` секунд.
- **User-Agent.** Ротация реалистичных UA (`UserAgentRotator`).
- **Обработка блокировки.** Детект 403/captcha → `BlockedException` → cooldown,
  повтор, запись в лог; при систематических банах — `Log::error`-алерт и статус ошибки.

Что описано для прода (заглушка в паке):

- **Прокси.** Интерфейс `ProxyPool` + `NullProxyPool` (подключён в HTTP-слой);
  в проде — ротация резидентных/дата-центр прокси и привязка cookies к прокси.
- **Общий rate-limit** на хост/аккаунт и алерты в мониторинг.

## API

Все маршруты, кроме `login`/`ping`, под `auth:sanctum`.

| Метод | Endpoint | Назначение |
|---|---|---|
| `GET` | `/api/ping` | проверка доступности |
| `POST` | `/api/login` | вход |
| `POST` | `/api/logout` | выход |
| `GET` | `/api/user` | текущий пользователь |
| `GET` | `/api/organization` | организация + агрегаты + статус (`data: null`, если нет) |
| `POST` | `/api/organization` | сохранить/обновить URL, запустить парсинг (201/200) |
| `POST` | `/api/organization/refresh` | повторный парсинг (202; 409 если уже идёт) |
| `GET` | `/api/organization/parse-status` | статус/прогресс последнего `parse_run` |
| `GET` | `/api/reviews?page=N&per_page=50` | отзывы, пагинация 50/стр (`per_page` до 100) |

Формат ошибок — стандартный Laravel: `422 { message, errors }`,
`401/404/409` с `message`.

## База данных

| Таблица | Ключевое |
|---|---|
| `users` | сид-пользователь (регистрации нет) |
| `organizations` | `user_id`, `url`, `business_id`, `title`, `address`, `rating`, `ratings_count`, `reviews_count`, `parse_status`, `parse_error`, `last_parsed_at` |
| `reviews` | `organization_id`, `external_id`, `author`, `rating`, `text`, `published_at`; `unique(organization_id, external_id)`, `index(organization_id, published_at)` |
| `parse_runs` | `status`, `progress`, `total`, `error`, `started_at`, `finished_at` |
| `organization_snapshots` | агрегаты + `payload` JSON на каждый `parse_run` (`unique(parse_run_id)`) |
| `parse_changes` | `field`, `old`, `new` — «было → стало» между снимками |
| `sessions`, `cache`, `jobs`, `personal_access_tokens` | инфраструктура |

**Статусы:** `organizations.parse_status` ∈ `pending|queued|running|ready|partial|failed|source_changed|empty`;
`parse_runs.status` ∈ `queued|running|done|failed|partial|source_changed|empty`.

**Идемпотентность:** повторный парсинг делает upsert по
`(organization_id, external_id)` — дубли не создаются, записи обновляются;
«осиротевшие» отзывы удаляются только при полном парсинге.

**История изменений (`parse_changes`).** Записи «было → стало» между соседними
снимками (`field` ∈ `rating|ratings_count|reviews_count`). Пример запроса —
последние изменения по организации:

```sql
SELECT pc.field, pc.old, pc.new, pc.created_at
FROM parse_changes pc
WHERE pc.organization_id = ?
ORDER BY pc.id DESC
LIMIT 20;
```

То же через Eloquent:

```php
$organization->parseChanges()
    ->with('snapshot:id,parse_run_id,created_at')
    ->latest('id')
    ->limit(20)
    ->get(['field', 'old', 'new', 'snapshot_id', 'created_at']);
```

## Ответы на дополнительные требования (1–5)

**1. Устойчивость к смене разметки.** Парсер не возвращает «тихо» пустоту.
После декодирования JSON проверяется схема (`reviews`, `rating`, `ratingsCount`,
`reviewsCount`, `csrfToken`); при несовпадении — `SourceChangedException` и
статус `source_changed`. Аномалия `reviews_count > 0`, но собрано `0` →
`EmptyResponseException` / статус `empty`. Расхождение локального количества с
источником → `partial` + warning в логе. Есть smoke/unit-тесты на сохранённой
фикстуре: при изменении структуры тест падает раньше пользователя. Наружу —
`parse_status`/`parse_error`, понятное сообщение в UI и запись в лог.

**2. Обоснование подхода.** См. раздел «Сравнение стратегий»: выбран внутренний
JSON (быстро, дёшево, хорошо масштабируется), headless — резерв на случай смены
контракта/блокировки.

**3. Масштаб и фоновая обработка.** Парсинг выполняется в очереди
(`ParseOrganizationJob`, драйвер `database`), не синхронно в HTTP. У задачи
`tries=3`, экспоненциальный бэкофф, обновление `progress`/`total` в `parse_runs`,
`failed()` помечает организацию. Для ~50 филиалов — по задаче на организацию +
батчинг и общий троттлинг; прогресс виден в UI через автопolling.

**4. Анти-бан на объёме.** Реализованы троттлинг+jitter, бэкофф, ротация UA и
обработка 403/captcha; пул прокси описан и вынесен за интерфейс `ProxyPool`
(`NullProxyPool` подключён в HTTP-слой, при систематических банах — алерт в лог).

**5. Идемпотентность и история.** Upsert по `(organization_id, external_id)`;
`organization_snapshots` хранит агрегаты и `payload` на каждый прогон;
`parse_changes` фиксирует `field`, `old`, `new` между снимками. Полный diff-UI не
делали — модель данных и запись изменений есть.

## Тесты

```bash
cd app
php artisan test     # 54 теста, 187 утверждений
npm run build        # сборка SPA
# или внутри compose: docker compose exec app php artisan test
```

Покрыто: авторизация, API организации/отзывов (валидация, короткие ссылки,
пагинация, изоляция пользователей, условная очистка при новой URL),
ядро парсера на фикстурах, очередь (идемпотентность, ретраи,
snapshots/changes), поведение UI во время парсинга (polling, блокировка).

## Деплой

Демонстрация на внешнем хостинге в этой поставке **отложена** (нет
домена/доступов); пак готов к развёртыванию двумя путями.

**Вариант A — VPS + nginx + php-fpm + MySQL (без docker):**

1. Скопировать `app/` на сервер; `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, реальные `DB_*`,
   `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` под ваш домен, `APP_URL=https://…`.
3. `php artisan key:generate`, `php artisan migrate --seed`,
   `php artisan config:cache route:cache view:cache`.
4. Корень nginx — `app/public`. **Обязательно** запустить `queue:work`
   постоянно под supervisor/systemd, иначе парсинг не выполняется.
5. После каждого выката: `php artisan queue:restart`.

**Вариант B — этот docker-compose на VPS (рекомендуется):**

1. Скопировать весь `delivery/` на сервер; заполнить `app/.env` по
   `.env.production.example` (домен, `DB_PASSWORD`, `SEED_USER_*`, `APP_KEY`).
2. `docker compose up --build -d`, затем `key:generate` и `migrate --seed`
   (см. «Быстрый старт»).
3. Повесить reverse-proxy/SSL (443) поверх `web:80` при необходимости.

## Известные ограничения

- Демо на внешнем хостинге отсутствует (требуется домен/доступы).
- Пул прокси — интерфейс + `NullProxyPool` (прод-стратегия описана).
- Полный diff-UI «было → стало» не делали — данные (`parse_changes`) есть.

## Что доделали бы, имея больше времени

- Планировщик регулярного обновления карточек + алерты при бане/смене разметки.
- Полноценный diff-UI «было → стало» на основе `parse_changes`.
- Поддержка 2ГИС и других площадок через общий интерфейс стратегии.
- Экспорт отзывов (CSV/JSON), автоответы, виджет отзывов.
- Расширить функциональность раздела «Организации», чтобы можно было добавлять 
несколько записей и просматривать их комментарии.

---