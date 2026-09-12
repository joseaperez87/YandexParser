# Yandex Parser — интеграция с Яндекс.Картами

Небольшое приложение из двух экранов: подключение карточки организации на
Яндекс.Картах (ссылка + валидация) и вывод её данных — отзывов (до ~600),
среднего рейтинга, числа оценок и числа отзывов. Парсинг вынесен в отдельный
сервис, выполняется в очереди и устойчив к антиботу и смене разметки.

> Репозиторий приложения — каталог `backend/` (этот). Файлы `STACK.md`,
> `docs/specs/`, `implementation/` и `ТЗ.md` лежат в корне проекта, рядом с
> репозиторием.

- **Стек и требования:** `STACK.md`
- **Спецификации по разделам ТЗ:** `docs/specs/`
- **Регистр реализации:** `implementation/`
- **Исходное ТЗ:** `ТЗ.md`

## Возможности

- Авторизация логин/пароль одним сид-пользователем (Sanctum, SPA cookie-based).
- Экран «Настройки»: вставка ссылки Яндекс.Карт, валидация (включая короткие
  ссылки), сохранение и запуск парсинга; статус и прогресс.
- Экран «Отзывы»: карточка организации + агрегаты (рейтинг, оценки, отзывы —
  раздельно), список отзывов (автор, дата, оценка, текст), пагинация 50/стр
  без перезагрузки.
- Parser: внутренний JSON-endpoint карточки (основная стратегия) + headless
  Playwright (fallback), троттлинг, ретраи/бэкофф, ротация User-Agent.
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

## Требования

- PHP 8.2+ (проверено на 8.4), Composer 2
- Node.js 20+ и npm
- MySQL 8 (например, через WAMP)
- **Настроенный CA bundle для HTTPS** (иначе cURL падает с
  `cURL error 60: SSL certificate problem`). В `php.ini` должны быть заданы
  `curl.cainfo` и `openssl.cafile` (путь к `cacert.pem`). Если настроить нельзя,
  можно для локальной разработки указать `YANDEX_MAPS_VERIFY_SSL=false` или
  путь к CA-бандлу в `.env`.

## Быстрый старт (WAMP)

```bash
# команды выполняются из корня репозитория (каталог backend/)
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

Демо-доступ задаётся в `.env`:

- `SEED_USER_EMAIL` (по умолчанию `admin@example.com`)
- `SEED_USER_PASSWORD` (по умолчанию `password`)

> `queue:work` должен работать постоянно — иначе задача парсинга не выполнится
> и статус останется «в очереди». **Признак проблемы:** статус долго «в очереди»,
> а прогресс не растёт → воркер очереди не запущен.

## Очередь (фоновый парсинг)

Парсинг выполняется в фоне через очередь (`QUEUE_CONNECTION=database`). Пока
воркер не запущен, задачи лежат в таблице `jobs`, а статус организации остаётся
«в очереди» и прогресс не растёт.

```bash
# Терминал 1 — HTTP-сервер
php artisan serve                # http://localhost:8000

# Терминал 2 — воркер очереди (обязателен для парсинга)
php artisan queue:work           # постоянный воркер (как в проде)
# или, для разработки (перечитывает код):
php artisan queue:listen --tries=1

# Альтернатива: всё сразу одной командой (Serve + Queue + Logs + Vite)
composer run dev
```

Полезные команды:

```bash
php artisan queue:work --once     # обработать одну задачу и выйти
php artisan queue:work --tries=3  # число попыток для задачи
php artisan queue:restart         # перезапустить воркеры (после деплоя)
php artisan queue:failed          # список упавших задач
php artisan queue:retry all       # повторить все упавшие
php artisan queue:flush           # очистить упавшие задачи
php artisan queue:clear           # очистить ожидающие задачи
```

> В продакшене `php artisan queue:work --tries=3` запускается постоянно под
> процесс-менеджером (supervisor/systemd). После выката нового кода выполните
> `php artisan queue:restart`, чтобы воркеры подхватили изменения.

## Переменные окружения

| Переменная | Значение по умолчанию | Назначение |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | окружение и отладка |
| `APP_URL` | `http://localhost:8000` | базовый URL приложения |
| `DB_CONNECTION` | `mysql` | драйвер БД |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | сервер MySQL |
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
| `YANDEX_MAPS_THROTTLE_MS` | `800` | пауза между запросами страниц |
| `YANDEX_MAPS_HEADLESS_ENABLED` | `false` | включить fallback Playwright |
| `YANDEX_MAPS_REVIEWS_ENDPOINT` | `/maps/api/business/fetchReviews` | внутренний endpoint |
| `YANDEX_MAPS_LOCALE` | `ru_RU` | локаль внутреннего API |
| `YANDEX_MAPS_RANKING` | `by_relevance_org` | сортировка отзывов |
| `YANDEX_MAPS_MAX_PAGES` | `12` | максимум страниц (как в UI, ~600 отзывов) |
| `YANDEX_MAPS_RETRIES` | `3` | повторы при блокировке |
| `YANDEX_MAPS_BACKOFF_BASE_MS` | `1000` | база экспоненциального бэкоффа |

## Архитектура

```
backend/
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
`Services/YandexMaps` (см. `docs/specs/04-parsing.md`).

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
схемы (`SourceChangedException`) или блокировке.

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

- **Троттлинг.** Пауза `YANDEX_MAPS_THROTTLE_MS` (+ jitter) между страницами.
- **Бэкофф.** При `BlockedException` (403/captcha) — экспоненциальная задержка
  и повтор в `YandexMapsParser`; сама задача имеет `tries=3` и бэкофф `[10,60,300]` секунд.
- **User-Agent.** Ротация реалистичных UA (`UserAgentRotator`).
- **Обработка блокировки.** Детект 403/captcha → `BlockedException` → cooldown,
  повтор, запись в лог; при систематических банах — статус ошибки.

Что описано для прода (заглушка в репозитории):

- **Прокси.** Интерфейс `ProxyPool` + `NullProxyPool`; в проде — ротация
  резидентных/дата-центр прокси и привязка cookies к прокси.
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
батчинг и общий троттлинг; прогресс виден в UI.

**4. Анти-бан на объёме.** Реализованы троттлинг+jitter, бэкофф, ротация UA и
обработка 403/captcha; пул прокси описан и вынесен за интерфейс `ProxyPool`.

**5. Идемпотентность и история.** Upsert по `(organization_id, external_id)`;
`organization_snapshots` хранит агрегаты и `payload` на каждый прогон;
`parse_changes` фиксирует `field`, `old`, `new` между снимками. Полный diff-UI не
делали — модель данных и запись изменений есть.

## Тесты

```bash
php artisan test     # 47 тестов, 160 утверждений
npm run build        # сборка SPA
```

Покрыто: авторизация, API организации/отзывов (валидация, короткие ссылки,
пагинация, изоляция пользователей), ядро парсера на фикстурах, очередь
(идемпотентность, ретраи, snapshots/changes).

## Деплой

Демонстрация на хостинге в этой поставке **отложена** (см. «Известные
ограничения»); ниже — как развернуть.

**Вариант A — VPS + nginx + php-fpm + MySQL:**

1. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, реальные `DB_*`,
   `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` под ваш домен, `APP_URL=https://…`.
3. `php artisan key:generate`, `php artisan migrate --seed`,
   `php artisan config:cache route:cache view:cache`.
4. Корень nginx — `backend/public`. **Обязательно** запустить `queue:work`
   постоянно (например, supervisor), иначе парсинг не выполняется.
5. SSL: на проде обычно хватает системного CA; при необходимости —
   `YANDEX_MAPS_VERIFY_SSL=true` (по умолчанию) и настроенный CA bundle в PHP.

**Вариант B — docker-compose (описан, в репозиторий не включён):**
сервисы `app` (php-fpm + nginx), `db` (MySQL 8), `worker`
(`php artisan queue:work`); сборка образа выполняет `composer install` и
`npm run build`. При необходимости отдельный профиль с Node + Playwright для
headless.

## Известные ограничения

- Демо на хостинге в этой поставке отсутствует (требуется домен/доступы).
- `docker-compose.yml` не включён — задокументирован как вариант.
- Обновление статуса парсинга в UI — вручную кнопкой (polling не реализован).
- Пул прокси — интерфейс + заглушка (прод-стратегия описана).

## Что доделали бы, имея больше времени

- Полный пул прокси с ротацией и health-check.
- Планировщик регулярного обновления карточек + алерты при бане/смене разметки.
- Полноценный diff-UI «было → стало» на основе `parse_changes`.
- Поддержка 2ГИС и других площадок через общий интерфейс стратегии.
- Экспорт отзывов (CSV/JSON), автоответы, виджет отзывов.
- Интеграционные тесты против живого источника в CI (помечены `@live`).

---

## Resumen (ES)

Integración con Yandex Maps: conectar una ficha de organización y mostrar
reseñas (~600), rating y contadores. Stack: Laravel 12 + SPA Vue 3 + MySQL 8,
cola `database`, Sanctum. Arranque local en `backend/` (WAMP): `composer install`,
`.env`, `migrate --seed`, `php artisan serve`, `php artisan queue:work`
(obligatorio), `npm run dev|build`. El parser vive en `Services/YandexMaps`:
estrategia principal por JSON interno de la ficha, fallback headless con
Playwright, anti-bot (throttle, backoff, rotación de User-Agent, `ProxyPool`).
Estados y errores visibles en la UI; idempotencia por `(organization_id,
external_id)` con snapshots y `parse_changes` (antes → después). El deploy se
documenta (VPS nginx+php-fpm+MySQL+supervisor; docker-compose como opción) y la
demo en hosting queda pendiente. Tests con `php artisan test` y build con
`npm run build`.
