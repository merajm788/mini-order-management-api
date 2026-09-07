# Mini Order Management API

A REST API where users register, list products for sale, and place orders
against each other's listings. Built with Laravel 13 on PHP 8.4 and MySQL 8.

The interesting parts are the order placement path (row locking so stock can't
be oversold), the Redis cache in front of the product catalogue, and the way
cache invalidation is handled without relying on cache tags.

## Contents

- [Running it](#running-it)
- [What you get](#what-you-get)
- [API endpoints](#api-endpoints)
- [Response format](#response-format)
- [How an order is placed](#how-an-order-is-placed)
- [R&D features](#rd-features)
- [Architecture](#architecture)
- [Database](#database)
- [Tests](#tests)
- [Decisions and trade-offs](#decisions-and-trade-offs)

## Running it

### With Docker

```bash
git clone <repository-url> mini-order-management-api
cd mini-order-management-api

cp .env.example .env

docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

You don't need to edit `.env`. The compose file passes the in-network hostnames
(`mysql`, `redis`, `mailpit`) to the containers that need them, while the
`.env` values stay pointed at the published host ports so `php artisan` also
works from your own shell.

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

### Middle option

The way this was actually developed: MySQL and Redis in containers, PHP on the
host. Fastest to iterate on, and the `.env.example` ports are already set for it.

```bash
docker compose up -d mysql redis mailpit
php artisan migrate --seed
php artisan serve
```

## What you get

| | URL |
| --- | --- |
| API | http://localhost:8000/api/v1 |
| Swagger UI | http://localhost:8000/docs |
| OpenAPI spec | http://localhost:8000/docs/openapi.yaml |
| Mailpit — catches every outgoing email | http://localhost:8025 |
| phpMyAdmin | http://localhost:8080 |
| MySQL | `localhost:3307`, user `laravel`, password `password` |
| Redis | `localhost:6380` |

MySQL and Redis are published on 3307 and 6380 rather than their defaults, so
they don't collide with anything already running on the host. That also means a
phpMyAdmin installed on your machine won't see this database — it talks to 3306.
Use the containerised one at port 8080 instead, or point a GUI client at 3307.

Swagger UI is loaded from a CDN and pointed at the checked-in
[`docs/openapi.yaml`](docs/openapi.yaml) — no documentation package, no build
step. You can send authenticated requests straight from that page: hit
**Authorize** and paste a token from `POST /login`.

There's also a Postman collection at
[`docs/postman_collection.json`](docs/postman_collection.json) with all 15
requests. Run **Auth → Login** first and its test script stores the token for
everything else. It includes a deliberate insufficient-stock request so you can
see the error payload, and the order requests assert that the total matches the
sum of its line items.

### Mailpit

Order confirmations are sent from a queued job, so they land in Mailpit a second
or two after you place an order. Open http://localhost:8025 and you'll see the
message with its line-item table and total.

Under Docker the `queue` container already runs `php artisan queue:work`, so
this happens on its own. Running on the host, start a worker yourself:

```bash
php artisan queue:work --queue=orders,default
```

### Looking at the data

phpMyAdmin at http://localhost:8080 — log in as `laravel` / `password`, or
`root` / `password` if you want to see both schemas.

From the terminal, either straight into MySQL:

```bash
docker compose exec mysql mysql -u laravel -ppassword mini_order_management
```

or through Tinker, which is nicer when you want the relationships:

```bash
docker compose exec app php artisan tinker
```

```php
Product::where('stock', 0)->get(['id', 'name']);
Order::with('items')->find(1);
User::firstWhere('email', 'demo@example.com')->products()->count();
```

Worth doing once: open an order's `order_items` rows and compare `product_name`
and `unit_price` against the current `products` row. They're snapshots, so
renaming or repricing a product leaves old invoices alone.

### Seeded accounts

| Email | Password | What they have |
| --- | --- | --- |
| `demo@example.com` | `password123` | Owns the 11 hand-written products |
| `customer@example.com` | `password123` | Has 5 sample orders |

Seeding creates 10 users, 23 products and 5 orders in total. The catalogue
deliberately includes one out-of-stock product and one inactive one, so the
filters and the rejection paths have something to work against.

## API endpoints

Base URL `http://localhost:8000/api/v1`.

### Auth

| | Endpoint | Token | |
| --- | --- | :---: | --- |
| `POST` | `/register` | — | Create an account, get a token back |
| `POST` | `/login` | — | Exchange credentials for a token |
| `POST` | `/logout` | yes | Revoke only the token used for this request |
| `GET` | `/me` | yes | The current user |

### Products

| | Endpoint | Token | |
| --- | --- | :---: | --- |
| `GET` | `/products` | — | List, with search/filter/sort/pagination |
| `GET` | `/products/{id}` | — | One product |
| `POST` | `/products` | yes | Create; the caller becomes the owner |
| `PUT` `PATCH` | `/products/{id}` | yes | Update — owner only |
| `DELETE` | `/products/{id}` | yes | Soft delete — owner only |

Query parameters for the listing:

| Parameter | | |
| --- | --- | --- |
| `search` | string | Matches name, SKU or description |
| `min_price` `max_price` | number | Inclusive range |
| `in_stock` | bool | `1` = has stock, `0` = out of stock |
| `is_active` | bool | Defaults to active only |
| `sort_by` | enum | `name`, `price`, `stock`, `created_at` |
| `sort_direction` | enum | `asc` or `desc` (default `desc`) |
| `per_page` | int | 1–100, default 15 |
| `page` | int | Default 1 |

### Orders

| | Endpoint | Token | |
| --- | --- | :---: | --- |
| `POST` | `/orders` | yes | Place an order |
| `GET` | `/orders` | yes | Your orders; filter with `?status=` |
| `GET` | `/orders/{id}` | yes | One order with its line items |
| `POST` | `/orders/{id}/cancel` | yes | Cancel and put the stock back |

### Placing an order

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
        "order_number": "ORD-20260907-B67F1C",
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

## Response format

Everything comes back in the same envelope, so a client only writes one
response handler.

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

Framework exceptions are converted in `bootstrap/app.php`, so no controller
carries a `try/catch` for them.

## How an order is placed

`OrderService::placeOrder()` does the four things the brief asks for, plus the
locking that makes them safe under concurrency.

```php
$order = DB::transaction(function () use ($user, $quantities, $notes): Order {
    $ids = array_keys($quantities);
    sort($ids);                                        // deadlock avoidance

    $products = $this->products->lockForOrdering($ids) // SELECT ... FOR UPDATE
        ->keyBy('id');

    $this->ensureProductsCanBeOrdered($products, $quantities);   // 1. checks

    $lineItems = $this->buildOrderItems($products, $quantities);

    $order = $this->orders->create([
        // ...
        'total_amount' => $this->calculateTotal($lineItems),     // 3. total
    ]);

    $this->orders->addItems($order, $lineItems);                 // 4. items

    foreach ($quantities as $productId => $quantity) {
        $this->products->reduceStock($products[$productId], $quantity);  // 2.
    }

    return $order;
});

ProcessOrder::dispatch($order->id)->afterCommit();
```

### The overselling problem

The obvious version has a race:

```php
if ($product->stock >= $qty) {   // two requests can both get past here
    $product->decrement('stock', $qty);
}
```

Two simultaneous requests for the last item both read `stock = 1`, both pass,
and stock ends up at `-1`.

`SELECT ... FOR UPDATE` inside a transaction fixes it. The first request locks
the rows; the second blocks until the first commits, then re-reads the real
value and fails properly. Products are locked in sorted id order so two carts
holding the same products can't deadlock each other.

### The rest of it

| | |
| --- | --- |
| Atomicity | One bad line rolls back the whole order — no partial writes |
| Money | `bcadd`/`bcmul` over `DECIMAL(10,2)`; floats drift on large carts |
| History | Line items snapshot `product_name` and `unit_price`, so renaming or repricing a product never rewrites a past invoice |
| Errors | Every shortage is returned at once, so a client can fix the whole cart in one round trip |
| Jobs | `->afterCommit()` means the worker can't pick up an order that later rolled back |

A rejected order tells you exactly what went wrong:

```json
{
    "success": false,
    "message": "One or more products do not have enough stock.",
    "errors": {
        "stock": [
            { "product_id": 4, "product_name": "27 Inch 4K Monitor",
              "requested": 900, "available": 20 }
        ]
    }
}
```

## R&D features

### Redis caching for products

In `app/Repositories/ProductRepository.php`. Both read paths — the paginated
listing and single-product lookups — are read-through caches:

```php
public function search(ProductFilters $filters): LengthAwarePaginator
{
    return Cache::remember(
        $this->versionedCacheKey($filters->toCacheKey()),
        config('cache.product_ttl'),
        fn () => $this->buildFilteredQuery($filters)->paginate($filters->perPage, page: $filters->page),
    );
}
```

`ProductFilters::toCacheKey()` hashes the filter values, so `?search=x&min_price=5`
and `?min_price=5&search=x` share one entry.

Invalidation was the interesting part. A listing produces one cache key per
filter combination, so a write can't enumerate what to delete. Redis tags solve
that, but `Cache::tags()` throws on the `array` and `database` stores, which
would make the test suite behave differently from production. So every key
carries a version number instead:

```php
private function versionedCacheKey(string $key): string
{
    return 'v'.Cache::get(self::VERSION_KEY, 1).':'.$key;
}

public function clearCache(): void
{
    Cache::add(self::VERSION_KEY, 1);   // must exist before incrementing
    Cache::increment(self::VERSION_KEY);
}
```

Bumping the version orphans every previous entry in O(1) and they expire on
their own TTL. Every write path calls `clearCache()`, including the stock
reduction from an order — so a customer never sees stock that isn't there.
`ProductCacheTest` asserts exactly that.

That `Cache::add()` before `increment()` matters more than it looks. On a cold
cache `increment()` returns `false` without storing anything, which pins the
version at its default and silently disables invalidation forever. The tests
caught it; manual clicking around didn't.

TTL is `PRODUCT_CACHE_TTL`, default 600s. The `FOR UPDATE` reads during checkout
deliberately bypass the cache — the whole point there is to read the live row.

### Rate limiting

Registered in `AppServiceProvider::configureRateLimiting()`, attached in
`bootstrap/app.php` and `routes/api.php`.

One blanket limit is the wrong shape for this API, so there are three:

| Limiter | Default | Keyed by | Applies to |
| --- | --- | --- | --- |
| `api` | 60/min | user id, else IP | everything under `/api` |
| `auth` | 5/min | IP | `/login`, `/register` |
| `orders` | 10/min | user id, else IP | `POST /orders` |

`auth` is keyed by IP because a brute-force attacker doesn't have a token yet.
The other two prefer the user id so that colleagues behind one office IP don't
throttle each other. `orders` is the tightest because it writes rows and takes
row locks.

All three are configurable — `RATE_LIMIT_API`, `RATE_LIMIT_AUTH`,
`RATE_LIMIT_ORDERS`. `RateLimitTest` covers that they fire, that they're tracked
per user rather than globally, and that browsing products isn't affected by the
auth limiter.

### Queued order processing

`app/Jobs/ProcessOrder.php`. Placing an order returns as soon as the transaction
commits; the confirmation email and the `pending → processing` transition run on
the `orders` queue backed by Redis.

- Only the order id is serialised, not the model, so the worker reads the
  committed row rather than a stale copy.
- `->afterCommit()` keeps a worker from picking up an order whose transaction
  later rolled back.
- `WithoutOverlapping` middleware stops two workers processing the same order.
- Three tries with a 10/30/60 second backoff; a permanent failure lands in
  `failed_jobs`.
- The job re-checks status, so an order cancelled between dispatch and pickup is
  skipped rather than resurrected.

### Order confirmation email

`app/Mail/OrderPlacedMail.php` with a Markdown template at
`resources/views/mail/orders/placed.blade.php`. It carries the order number, a
line-item table, the total and any note the customer left. Because it goes out
from the queued job, a slow SMTP server never delays the API response.

Everything lands in Mailpit at http://localhost:8025 in development.

### Product search filters

`app/DataTransferObjects/ProductFilters.php` plus
`ProductRepository::buildFilteredQuery()`. The query string is parsed once into
an immutable DTO, which is the only thing the repository ever sees.

Passing the DTO instead of the `Request` buys a few things:

- The repository never touches HTTP, so it stays unit-testable.
- The cache key becomes a pure function of the filter values.
- `sort_by` is whitelisted against `ProductFilters::SORTABLE`; anything else is
  a 422 rather than an interpolated column name.
- `LIKE` wildcards are escaped, so searching for `100%` is a literal search.
- `per_page` is capped at 100, so one request can't ask for the whole table.

The filters compose into a single query:

```
GET /products?search=keyboard&min_price=20&max_price=500&in_stock=1&sort_by=price&sort_direction=asc
```

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

Services take them by constructor injection:

```php
class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ProductRepository $products,
    ) {}
}
```

The payoff that mattered most here: `tests/Unit/Services/OrderServiceTest.php`
mocks both repositories and exercises the pricing and stock rules with no
database at all, in about 100ms. The database-backed feature tests take roughly
seven times longer for the same ground.

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

| Constraint | Rule | Why |
| --- | --- | --- |
| `products.user_id` | CASCADE | A deleted merchant takes their listings |
| `orders.user_id` | CASCADE | A deleted customer takes their history |
| `order_items.order_id` | CASCADE | Line items can't outlive their order |
| `order_items.product_id` | RESTRICT | A product referenced by an order can never be hard-deleted; deletion is soft |

Indexes: `products (is_active, created_at)` for the default catalogue listing,
`products (price)` for range filters and price sorting, `orders (user_id,
created_at)` for "my orders, newest first", and `orders (status)` for filtering.

Money is `DECIMAL`, not `FLOAT` — binary floats can't represent 19.99 exactly,
and across a large order that drift turns into a wrong total. Totals are summed
with bcmath for the same reason.

Line items snapshot the product name and price because an invoice is a
historical record. A merchant renaming or repricing a product must not change
what a past order says the customer bought.

## Tests

```bash
php artisan test                                   # all of it
php artisan test --testsuite=Unit                  # no database
php artisan test tests/Feature/Api/OrderTest.php   # one file
```

```
Tests:    80 passed (232 assertions)
Duration: ~1.4s
```

| Suite | Covers |
| --- | --- |
| `Feature/Api/AuthTest` | Register, login, logout scoping, token rejection, no account enumeration |
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

Two bugs came out of writing these rather than out of clicking around:

1. Inactive products were leaking into the public catalogue. `ProductFilters`
   defaulted `is_active` to `true` in its constructor, but `fromRequest()` passed
   `null` when the parameter was absent, which overrode the default.
2. Cache invalidation never fired at all, for the `Cache::increment()` reason
   described above. Customers would have seen stale stock indefinitely.

## Decisions and trade-offs

**Caching inside the repository, not as a decorator.** A `CachedProductRepository`
wrapping a plain one would separate the two concerns more cleanly. At this size
the extra indirection costs more in readability than it returns, and since every
caller already goes through `ProductRepository`, it can be split out later
without changing a single call site.

**A version counter instead of cache tags.** Tags are more precise but only work
on Redis and Memcached. The counter behaves identically on every store, which
keeps the test suite honest about production. The cost is that unrelated entries
get orphaned by any product write — acceptable, since they expire on their own
and product writes are rare next to reads.

**`GET /products/{id}` takes an id, not a bound model.** Route-model binding
would query the database directly and skip the cache, so that one endpoint
resolves through the repository instead.

**404, not 403, for someone else's order.** `OrderRepository::findUserOrder()`
scopes by owner, so a foreign order id is indistinguishable from a missing one.
A 403 would confirm the order exists.

**One role, not merchant vs customer.** The brief says "users can create products
and place orders", so this is a marketplace — every account can do both, the way
Etsy or OLX work rather than a storefront with separate seller accounts. That
leaves two rules, and both are enforced:

| | Own product | Someone else's |
| --- | :---: | :---: |
| Update / delete | yes | 403 |
| Order | yes | yes |

Editing is owner-only through `ProductPolicy`, which is the real security
boundary and is covered by `ProductTest`. Ordering is open to everyone, a seller
buying their own listing included. Nothing breaks when they do, and with no
payments in the system there's no incentive to game it. Real marketplaces only
block self-purchase once commission or seller ratings are at stake.

**Soft deletes on products only.** Orders and their items are financial records
and are never deleted. Products are soft-deleted so `order_items.product_id`
stays valid, which is what makes the RESTRICT foreign key safe.

**Route patterns instead of per-route constraints.** `routes/api.php` declares
`Route::pattern('product', '[0-9]+')` once rather than chaining `whereNumber()`
onto seven routes. Without it, `/products/abc` reaches a controller typed
`int $product` and produces a 500 instead of a 404.

**DNS validation on registration only in production.** `email:rfc,dns` catches
typo'd domains but costs a live MX lookup per request, which makes tests slow
and flaky. The rule is applied only when `app()->isProduction()`.

**`preventLazyLoading()` outside production.** An accidental N+1 raises an
exception in development instead of quietly degrading production.

