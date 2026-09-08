# Mini Order Management API

A REST API where users register, list products for sale, and place orders
against each other's listings. Built with Laravel 13, PHP 8.4 and MySQL 8.

Placing an order checks stock, deducts it inside a locked transaction so it
cannot be oversold, calculates the total, saves the line items, and queues a
confirmation email.

## Contents

- [Setup](#setup)
- [URLs](#urls)
- [Seeded accounts](#seeded-accounts)
- [API endpoints](#api-endpoints)
- [Response format](#response-format)
- [R&D features](#rd-features)
- [Architecture](#architecture)
- [Database](#database)
- [Tests](#tests)

## Setup

### With Docker

```bash
git clone <repository-url> mini-order-management-api
cd mini-order-management-api

cp .env.example .env

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The `composer install` step looks redundant next to `--build`, and isn't. The
image does install dependencies, but the compose file bind mounts your project
directory over `/var/www/html` so that code edits show up without a rebuild —
and that mount hides the image's `vendor/`. A fresh clone has no `vendor/` of
its own (it's gitignored), so the container sees an empty directory until you
install into the mount. Running it there also leaves `vendor/` on the host,
which is what your editor and `./vendor/bin/pint` need.

Nothing else needs editing. The compose file passes the in-network hostnames
(`mysql`, `redis`, `mailpit`) to the containers, while the `.env` values stay
pointed at the published host ports so `php artisan` also works from your own
shell.

If your account isn't uid/gid 1000, build with
`UID=$(id -u) GID=$(id -g) docker compose up -d --build` so the container can
write to the bind-mounted `storage/` directory.

### Without Docker

Needs PHP 8.3+ with `pdo_mysql`, `redis` and `bcmath`, plus Composer 2,
MySQL 8 and Redis 7.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create both databases — the test suite runs against MySQL, not SQLite:

```sql
CREATE DATABASE mini_order_management       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE mini_order_management_test  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'laravel'@'localhost' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON mini_order_management.*      TO 'laravel'@'localhost';
GRANT ALL PRIVILEGES ON mini_order_management_test.* TO 'laravel'@'localhost';
FLUSH PRIVILEGES;
```

Set `DB_PORT=3306` and `REDIS_PORT=6379` in `.env` (the defaults are 3307/6380
for the Docker setup), then:

```bash
php artisan migrate --seed
php artisan serve                                # terminal 1
php artisan queue:work --queue=orders,default    # terminal 2
```

#### Somewhere for the emails to go

Mailpit is a container, so without Docker nothing is listening on the SMTP port
and sending an order confirmation fails. Pick one of these.

**Install Mailpit natively** — keeps the web UI at http://localhost:8025 and
needs no `.env` change, since it uses the same 8025/1025 ports:

```bash
sudo bash -c "$(curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh)"
mailpit
```

Other platforms: `brew install mailpit` on macOS, or grab a binary from
[github.com/axllent/mailpit/releases](https://github.com/axllent/mailpit/releases).

**Run only that container**, if Docker is available but you want the rest on
the host:

```bash
docker compose up -d mailpit
```

**Write to the log instead**, with no mail server at all. Set `MAIL_MAILER=log`
in `.env` and the full email lands in the log file:

```bash
tail -f storage/logs/laravel.log
```

### MySQL and Redis in Docker, PHP on the host

Fastest for development, and the `.env.example` ports are already set for it:

```bash
docker compose up -d mysql redis mailpit
php artisan migrate --seed
php artisan serve
php artisan queue:work --queue=orders,default    # second terminal
```

## URLs

| | |
| --- | --- |
| API | http://localhost:8000/api/v1 |
| Swagger UI | http://localhost:8000/docs |
| OpenAPI spec | http://localhost:8000/docs/openapi.yaml |
| Mailpit — every outgoing email lands here | http://localhost:8025 |
| phpMyAdmin | http://localhost:8080 |
| MySQL | `localhost:3307`, user `laravel`, password `password` |
| Redis | `localhost:6380` |

MySQL and Redis use 3307 and 6380 rather than their defaults so they don't
collide with anything already on the host. A phpMyAdmin installed on your
machine talks to 3306 and won't see this database — use the containerised one at
port 8080, or point a GUI client at 3307.

**Swagger UI** is served from a CDN against the checked-in
[`docs/openapi.yaml`](docs/openapi.yaml). You can send authenticated requests
from that page: hit **Authorize** and paste a token from `POST /login`.

**Postman**: import [`docs/postman_collection.json`](docs/postman_collection.json).
Run **Auth → Login** first — its test script stores the token, and every other
request picks it up.

**Mailpit** and **phpMyAdmin** come from containers, so those two URLs only work
when Docker is running. Mailpit catches order confirmations, which arrive a
second or two after an order is placed — the `queue` container runs the worker
already; on the host, start one yourself with
`php artisan queue:work --queue=orders,default`.

## Seeded accounts

| Email | Password | |
| --- | --- | --- |
| `demo@example.com` | `password123` | Owns the 11 hand-written products |
| `customer@example.com` | `password123` | Has 5 sample orders |

Seeding creates 10 users, 23 products and 5 orders. The catalogue includes one
out-of-stock and one inactive product so the filters and rejection paths have
something to work against.

## API endpoints

Base URL `http://localhost:8000/api/v1`. Protected endpoints expect
`Authorization: Bearer <token>`.

### Auth

| | Endpoint | Token | |
| --- | --- | :---: | --- |
| `POST` | `/register` | — | Create an account, get a token back |
| `POST` | `/login` | — | Exchange credentials for a token |
| `POST` | `/logout` | yes | Revoke the token used for this request |
| `GET` | `/me` | yes | The current user |

`register` takes `name`, `email`, `password`, `password_confirmation` and an
optional `device_name`, which names the token so a user can revoke one device
without signing out everywhere. `login` takes `email`, `password` and the same
optional `device_name`.

### Products

| | Endpoint | Token | |
| --- | --- | :---: | --- |
| `GET` | `/products` | — | List, with search/filter/sort/pagination |
| `GET` | `/products/{id}` | — | One product |
| `POST` | `/products` | yes | Create; the caller becomes the owner |
| `PUT` | `/products/{id}` | yes | Update — owner only, every field optional |
| `DELETE` | `/products/{id}` | yes | Soft delete — owner only |

Create takes `name`, `price` and `stock`, plus optional `sku` (generated when
omitted), `description` and `is_active`.

Query parameters for the listing:

| Parameter | | |
| --- | --- | --- |
| `search` | string | Matches name, SKU or description |
| `min_price` `max_price` | number | Inclusive range |
| `in_stock` | bool | `true`/`1` = in stock, `false`/`0` = out of stock |
| `is_active` | bool | Defaults to active only |
| `sort_by` | enum | `name`, `price`, `stock`, `created_at` |
| `sort_direction` | enum | `asc` or `desc` (default `desc`) |
| `per_page` | int | 1–100, default 15 |
| `page` | int | Default 1 |

```
GET /products?search=keyboard&min_price=20&max_price=500&in_stock=true&sort_by=price&sort_direction=asc
```

### Orders

| | Endpoint | Token | |
| --- | --- | :---: | --- |
| `POST` | `/orders` | yes | Place an order |
| `GET` | `/orders` | yes | Your orders; filter with `?status=` |
| `GET` | `/orders/{id}` | yes | One order with its line items |
| `POST` | `/orders/{id}/cancel` | yes | Cancel and return the stock |

Statuses are `pending`, `processing`, `completed` and `cancelled`. Only
`pending` and `processing` orders can be cancelled.

### Example: placing an order

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"customer@example.com","password":"password123"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN),true)["data"]["token"];')

curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"items":[{"product_id":1,"quantity":2},{"product_id":2,"quantity":1}],
       "notes":"Please deliver after 6pm."}'
```

```json
{
    "data": {
        "id": 6,
        "order_number": "ORD-20260908-B67F1C",
        "status": "pending",
        "total_amount": 139.48,
        "notes": "Please deliver after 6pm.",
        "items": [
            { "id": 16, "product_id": 1, "product_name": "Wireless Mouse",
              "unit_price": 24.99, "quantity": 2, "subtotal": 49.98 },
            { "id": 17, "product_id": 2, "product_name": "Mechanical Keyboard",
              "unit_price": 89.5, "quantity": 1, "subtotal": 89.5 }
        ]
    },
    "success": true,
    "message": "Order placed successfully."
}
```

Ordering more than the available stock returns `422` and names every shortage in
one response, so a client can fix the whole cart in one round trip:

```json
{
    "success": false,
    "message": "One or more products do not have enough stock.",
    "errors": {
        "stock": [
            { "product_id": 4, "product_name": "27 Inch 4K Monitor",
              "requested": 900, "available": 25 }
        ]
    }
}
```

## Response format

Every response uses the same envelope, so a client writes one response handler.

```json
{ "success": true, "message": "Product retrieved successfully.", "data": {} }
```

```json
{ "success": false, "message": "The given data was invalid.",
  "errors": { "price": ["The price field is required."] } }
```

Paginated endpoints keep Laravel's `meta` and `links` blocks alongside it.

| Code | |
| --- | --- |
| 200 / 201 | OK / Created |
| 401 | Missing, invalid or expired token |
| 403 | Authenticated, but not the owner |
| 404 | Not found, or not visible to you |
| 409 | Conflict — e.g. cancelling a completed order |
| 422 | Validation failed, insufficient stock, or unavailable product |
| 429 | Rate limited |

## R&D features

### Redis caching for products

`app/Repositories/ProductRepository.php`. Both read paths — the paginated
listing and single-product lookups — are read-through caches, with the TTL in
`PRODUCT_CACHE_TTL` (default 600s).

A listing has one cache key per filter combination, so a write cannot enumerate
what to delete. Redis tags would solve that but only work on Redis and
Memcached, so every key carries a version number instead; a write increments it
and orphans all the old keys at once. Every write path does this, including the
stock reduction from an order, so a customer never sees stock that isn't there.

### API rate limiting

Three limiters, registered in `AppServiceProvider::configureRateLimiting()`:

| Limiter | Default | Keyed by | Applies to |
| --- | --- | --- | --- |
| `api` | 60/min | user id, else IP | everything under `/api` |
| `auth` | 5/min | IP | `/login`, `/register` |
| `orders` | 10/min | user id, else IP | `POST /orders` |

`auth` is keyed by IP because a brute-force attacker has no token yet. The other
two prefer the user id so colleagues behind one office IP don't throttle each
other. All three are configurable: `RATE_LIMIT_API`, `RATE_LIMIT_AUTH`,
`RATE_LIMIT_ORDERS`.

### Queued order processing

`app/Jobs/ProcessOrder.php`. Placing an order returns as soon as the transaction
commits; the confirmation email and the `pending → processing` transition run on
the `orders` queue backed by Redis.

Only the order id is serialised, not the model, so the worker reads the
committed row. `->afterCommit()` keeps a worker from picking up an order whose
transaction later rolled back, and `WithoutOverlapping` stops two workers
processing the same order. Three tries with a 10/30/60 second backoff; a
permanent failure lands in `failed_jobs`.

### Order confirmation email

`app/Mail/OrderPlacedMail.php` with a Markdown template at
`resources/views/mail/orders/placed.blade.php` — order number, line-item table,
total and any customer note. It goes out from the queued job, so a slow SMTP
server never delays the API response.

### Product search filters

`app/DataTransferObjects/ProductFilters.php`. The query string is parsed once
into an immutable object, which is the only thing the repository sees — so the
repository never touches HTTP and stays unit-testable, and the cache key becomes
a pure function of the filter values.

`sort_by` is whitelisted, so anything else is a `422` rather than an
interpolated column name. `LIKE` wildcards are escaped, so searching for `100%`
is a literal search. `per_page` is capped at 100.

## Architecture

```
Request
  → route + middleware (auth:sanctum, throttle:api|auth|orders)
  → FormRequest      validation
  → Controller       unpack, call a service, wrap in a Resource
  → Service          business rules: stock, totals, transactions
  → Repository       every database query, plus its caching
  → Eloquent → MySQL
```

Every database query lives in one of three repositories and nothing above them
touches Eloquent:

| | |
| --- | --- |
| `ProductRepository` | Product reads and writes, plus the Redis cache |
| `OrderRepository` | Orders and their line items |
| `UserRepository` | User lookup and creation |

| Path | |
| --- | --- |
| `app/Http/Controllers/Api/V1/` | Thin HTTP controllers |
| `app/Http/Requests/` | Validation per endpoint |
| `app/Http/Resources/` | JSON shaping |
| `app/Http/Responses/ApiResponse.php` | The shared envelope |
| `app/Services/` | Business logic |
| `app/Repositories/` | All database access |
| `app/DataTransferObjects/` | `ProductFilters` |
| `app/Enums/` | `OrderStatus` |
| `app/Exceptions/` | Domain exceptions that render themselves |
| `app/Jobs/` | `ProcessOrder` |
| `app/Mail/` | `OrderPlacedMail` |
| `app/Policies/` | Ownership checks |
| `docker/` | Dockerfile, nginx config, MySQL init |
| `docs/` | OpenAPI spec, Postman collection |

## Database

```
users ─┬─< products ─┐
       │             │
       └─< orders ─< order_items
```

| Table | Notable columns |
| --- | --- |
| `users` | `email` unique; Sanctum tokens live in `personal_access_tokens` |
| `products` | `user_id` FK, `sku` unique, `price` DECIMAL(10,2), `stock`, `is_active`, `deleted_at` |
| `orders` | `user_id` FK, `order_number` unique, `status`, `total_amount` DECIMAL(12,2) |
| `order_items` | `order_id` FK, `product_id` FK, `product_name`, `unit_price`, `quantity`, `subtotal`, unique on `(order_id, product_id)` |

Foreign keys:

| Constraint | Rule | |
| --- | --- | --- |
| `products.user_id` | CASCADE | A deleted merchant takes their listings |
| `orders.user_id` | CASCADE | A deleted customer takes their history |
| `order_items.order_id` | CASCADE | Line items can't outlive their order |
| `order_items.product_id` | RESTRICT | A product in an order can never be hard-deleted; deletion is soft |

Indexes: `products (is_active, created_at)` for the default listing,
`products (price)` for range filters and price sorting, `orders (user_id,
created_at)` for "my orders, newest first", and `orders (status)` for filtering.

Money is `DECIMAL`, not `FLOAT`, and totals are summed with bcmath — binary
floats can't represent 19.99 exactly, and across a large order that drift turns
into a wrong total.

Line items store `product_name` and `unit_price` as well as `product_id`,
because an invoice is a historical record. Renaming or repricing a product must
not change what a past order says the customer bought.

## Tests

```bash
php artisan test                                   # all of it
php artisan test --testsuite=Unit                  # no database
php artisan test tests/Feature/Api/OrderTest.php   # one file
```

```
Tests:    83 passed (238 assertions)
Duration: ~1.4s
```

| Suite | Covers |
| --- | --- |
| `Feature/Api/AuthTest` | Register, login, logout scoping, token rejection, device names |
| `Feature/Api/ProductTest` | CRUD, search/filter/sort, ownership 403s, validation, soft delete |
| `Feature/Api/OrderTest` | Totals, stock deduction, snapshotting, rollback, cancellation, cross-user isolation |
| `Feature/Api/ProductCacheTest` | Cache hits, per-filter keys, invalidation on every write |
| `Feature/Api/RateLimitTest` | All three limiters, per-user tracking, independence |
| `Feature/Jobs/ProcessOrderTest` | Status transition, mail dispatch, cancelled and missing orders |
| `Feature/DocumentationTest` | Docs routes and OpenAPI spec coverage |
| `Unit/Services/OrderServiceTest` | Pricing and stock rules against mocked repositories |

The suite runs against a real MySQL schema (`mini_order_management_test`) rather
than SQLite, so migrations, foreign keys and `SELECT ... FOR UPDATE` behave the
way they will in production. `RefreshDatabase` isolates each test.

## Licence

MIT.
