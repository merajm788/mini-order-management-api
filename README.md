# Mini Order Management API

A REST API where users register, list products for sale, and place orders
against each other's listings. Built with Laravel 13, PHP 8.4 and MySQL 8.

Anyone with an account can do both things — sell and buy — the way Etsy or OLX
work rather than a storefront with separate seller accounts. You can only edit
or delete the products you created, but you can order anyone's.

## Contents

- [Setup](#setup)
- [URLs](#urls)
- [Seeded accounts](#seeded-accounts)
- [How it works](#how-it-works)
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
docker compose exec -u "$(id -u)" app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

That's everything — no `.env` editing. The compose file gives the containers
the in-network hostnames (`mysql`, `redis`, `mailpit`), while the `.env` values
point at the published host ports so `php artisan` also works from your shell.

Two notes on that `composer install`:

- It isn't redundant next to `--build`. The image installs dependencies, but
  compose bind mounts your project directory over `/var/www/html` so code edits
  show up without a rebuild — and that mount hides the image's `vendor/`. A
  fresh clone has no `vendor/` of its own, so you install into the mount.
- `-u "$(id -u)"` matters. `docker compose exec` runs as root by default, and
  root-owned files in the mount stop PHP-FPM (which runs as your uid) from
  writing to `storage/`.

If your account isn't uid/gid 1000, build with
`UID=$(id -u) GID=$(id -g) docker compose up -d --build`.

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

Set `DB_PORT=3306` and `REDIS_PORT=6379` in `.env` (the defaults are 3307/6380,
for the Docker setup), then:

```bash
php artisan migrate --seed
php artisan serve                                # terminal 1
php artisan queue:work --queue=orders,default    # terminal 2
```

The queue worker is not optional — order confirmation emails and the
`pending → processing` transition both run on it.

#### Somewhere for the emails to go

Mailpit is a container, so without Docker nothing listens on the SMTP port and
sending a confirmation fails. Pick one:

```bash
# Install Mailpit natively — UI stays at http://localhost:8025, no .env change
sudo bash -c "$(curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh)"
mailpit
```

macOS: `brew install mailpit`. Or grab a binary from
[github.com/axllent/mailpit/releases](https://github.com/axllent/mailpit/releases).

```bash
# Or run just that one container
docker compose up -d mailpit
```

```bash
# Or skip the mail server: set MAIL_MAILER=log in .env and read the log
tail -f storage/logs/laravel.log
```

### MySQL and Redis in Docker, PHP on the host

Fastest for development, and the `.env.example` ports already match:

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
port 8080, or point a GUI client at 3307. Mailpit and phpMyAdmin both come from
containers, so those two URLs need Docker running.

**Swagger UI** is served from a CDN against the checked-in
[`docs/openapi.yaml`](docs/openapi.yaml). You can send real requests from that
page: hit **Authorize** and paste a token from `POST /login`.

**Postman**: import [`docs/postman_collection.json`](docs/postman_collection.json)
and run **Auth → Login** first. Its test script stores the token, and every
other request picks it up.

## Seeded accounts

| Email | Password | |
| --- | --- | --- |
| `demo@example.com` | `password123` | Owns the 11 hand-written products |
| `customer@example.com` | `password123` | Has 5 sample orders |

Seeding creates 10 users, 23 products and 5 orders. The catalogue includes one
out-of-stock and one inactive product so the filters and rejection paths have
something to work against.

## How it works

### Signing up and signing in

1. `POST /register` or `POST /login` returns a token.
2. Send it as `Authorization: Bearer <token>` on protected endpoints.
3. `POST /logout` revokes **only that token**. Other devices stay signed in.

Both endpoints accept an optional `device_name`. It names the token, so a user
can see "iphone-15" and "macbook-pro" in their sessions and revoke one without
signing out everywhere. Tokens expire after 30 days.

Login gives the same error for a wrong password and an unknown email, so the
response can't be used to check whether an address is registered.

### Listing a product

1. `POST /products` with `name`, `price` and `stock`.
2. The caller becomes the owner.
3. If you don't send a `sku`, one is generated from the name —
   `Wireless Mouse` becomes `WIRELESS-MOUSE-8F3A`.

Only the owner can update or delete it afterwards; anyone else gets a `403`.

### Browsing products

`GET /products` is public — no token needed. It supports search, price range,
stock and active filters, sorting and pagination, and they compose into a single
query.

Inactive products are hidden by default. A search that matches nothing is still
a `200` with an empty `data` array — only the message changes, to
`"No products found."` A missing id on `GET /products/{id}` is a `404`.

Results come from Redis when the cache is warm.

### Placing an order

`POST /orders` with a list of `product_id` and `quantity` pairs. Inside one
database transaction:

1. **Lock the products.** `SELECT ... FOR UPDATE` on every product in the cart,
   in sorted id order.
2. **Check them.** Each product must exist, be active, and have enough stock.
   If any fails, nothing is written and you get a `422` naming *every* problem
   at once, so the whole cart can be fixed in one round trip.
3. **Calculate the total** from the live prices, with bcmath.
4. **Save the line items,** each one storing the product's name and price as
   they are right now.
5. **Reduce the stock** by exactly what was ordered.

After the transaction commits, a job goes onto the queue. It moves the order
from `pending` to `processing` and emails the customer their confirmation.

The locking is what makes step 2 trustworthy. Without it, two people ordering
the last item could both pass the stock check and drive stock to `-1`. With it,
the second request waits for the first to commit, re-reads the real number, and
fails properly.

Line items keep a copy of the product name and price because an invoice is a
historical record. If the seller renames the product or changes its price
tomorrow, your old order still shows what you actually bought.

### Cancelling an order

`POST /orders/{id}/cancel` sets the status to `cancelled` and returns the stock.
Only `pending` and `processing` orders can be cancelled; anything else is a
`409`.

### When a product is deleted

Deleting a product is a soft delete, so old invoices keep working. Orders still
waiting on it need fixing, though:

1. The product is soft deleted and disappears from the catalogue.
2. A queued job finds every `pending` or `processing` order containing it.
3. That one line is removed from each order and the total is recalculated. The
   rest of the order ships as normal — an order for a phone and a pair of
   headphones does not lose the headphones because the phone went away.
4. An order left with no lines at all is cancelled instead.
5. Either way the customer gets an email saying what happened and what the new
   total is.

This runs on the queue because a popular product can be sitting in many open
orders.

### Reading your orders

`GET /orders` lists your own, newest first, and takes `?status=`. `GET /orders/{id}`
returns one with its line items. Both are scoped to you — someone else's order
id returns a `404`, not a `403`, so the response never confirms it exists.

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
optional `device_name`. `login` takes `email`, `password` and the same optional
`device_name`.

### Products

| | Endpoint | Token | |
| --- | --- | :---: | --- |
| `GET` | `/products` | — | List, with search/filter/sort/pagination |
| `GET` | `/products/{id}` | — | One product |
| `POST` | `/products` | yes | Create; the caller becomes the owner |
| `PUT` | `/products/{id}` | yes | Update — owner only, every field optional |
| `DELETE` | `/products/{id}` | yes | Soft delete — owner only |

`POST` takes `name`, `price` and `stock`, plus optional `sku`, `description`
and `is_active`.

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

Statuses are `pending`, `processing`, `completed` and `cancelled`.

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

Ordering more than the available stock:

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

`app/Repositories/ProductRepository.php`. The product listing and single-product
lookups are read-through caches, with the TTL in `PRODUCT_CACHE_TTL`
(default 600s).

A listing has one cache key per filter combination, so a write can't enumerate
what to delete. Redis tags would solve that but only work on Redis and
Memcached, so every key carries a version number instead — a write increments it
and orphans all the old keys at once. Every write path does this, including the
stock reduction from an order, so a customer never sees stock that isn't there.

### API rate limiting

Three limiters, in `AppServiceProvider::configureRateLimiting()`:

| Limiter | Default | Keyed by | Applies to |
| --- | --- | --- | --- |
| `api` | 60/min | user id, else IP | everything under `/api` |
| `auth` | 5/min | IP | `/login`, `/register` |
| `orders` | 10/min | user id, else IP | `POST /orders` |

`auth` is keyed by IP because a brute-force attacker has no token yet. The other
two prefer the user id so colleagues behind one office IP don't throttle each
other. Configurable with `RATE_LIMIT_API`, `RATE_LIMIT_AUTH`,
`RATE_LIMIT_ORDERS`.

### Queued order processing

Two jobs run on the `orders` queue, backed by Redis:

- `ProcessOrder` — moves a new order to `processing` and sends the confirmation.
- `HandleDeletedProductOrders` — strips a just-deleted product from the open
  orders holding it, re-totals them, and emails those customers.

Both carry only an id, not a model, so the worker reads committed rows.
`->afterCommit()` keeps a worker from picking up something whose transaction
later rolled back, and `WithoutOverlapping` stops two workers handling the same
order. Three tries with a 10/30/60 second backoff; a permanent failure lands in
`failed_jobs`.

### Emails

`OrderPlacedMail` and `OrderCancelledMail`, both Markdown templates in
`resources/views/mail/orders/`. Each carries the order number, a line-item
table and the total. They go out from queued jobs, so a slow SMTP server never
delays the API response.

### Product search filters

`app/DataTransferObjects/ProductFilters.php`. The query string is parsed once
into an immutable object, which is the only thing the repository sees — so the
repository never touches HTTP, and the cache key becomes a pure function of the
filter values.

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

Every database query lives in one of three repositories, and nothing above them
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
| `app/Jobs/` | `ProcessOrder`, `HandleDeletedProductOrders` |
| `app/Mail/` | `OrderPlacedMail`, `OrderCancelledMail` |
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
floats can't represent 19.99 exactly, and across a large order that turns into a
wrong total.

## Tests

```bash
php artisan test                                   # all of it
php artisan test --testsuite=Unit                  # no database
php artisan test tests/Feature/Api/OrderTest.php   # one file
```

```
Tests:    92 passed (259 assertions)
Duration: ~1.6s
```

| Suite | Covers |
| --- | --- |
| `Feature/Api/AuthTest` | Register, login, logout scoping, token rejection, device names |
| `Feature/Api/ProductTest` | CRUD, search/filter/sort, ownership 403s, validation, soft delete |
| `Feature/Api/OrderTest` | Totals, stock deduction, snapshotting, rollback, cancellation, cross-user isolation |
| `Feature/Api/ProductCacheTest` | Cache hits, per-filter keys, invalidation on every write |
| `Feature/Api/RateLimitTest` | All three limiters, per-user tracking, independence |
| `Feature/Jobs/ProcessOrderTest` | Status transition, mail dispatch, cancelled and missing orders |
| `Feature/Jobs/HandleDeletedProductOrdersTest` | Removing a deleted product's line, re-totalling, cancelling empty orders |
| `Feature/DocumentationTest` | Docs routes and OpenAPI spec coverage |
| `Unit/Services/OrderServiceTest` | Pricing and stock rules against mocked repositories |

The suite runs against a real MySQL schema (`mini_order_management_test`) rather
than SQLite, so migrations, foreign keys and `SELECT ... FOR UPDATE` behave the
way they will in production. `RefreshDatabase` isolates each test.

## Licence

MIT.
