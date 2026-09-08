<?php

namespace App\Repositories;

use App\DataTransferObjects\ProductFilters;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;

/**
 * Product database access, with a Redis cache in front of the two read paths.
 *
 * Cache keys carry a version number. A listing has one key per filter
 * combination, so a write cannot enumerate what to delete — it bumps the
 * version instead, orphaning every old key at once.
 */
class ProductRepository
{
    private const string VERSION_KEY = 'products:version';

    public function search(ProductFilters $filters): LengthAwarePaginator
    {
        // Cache plain rows, never the paginator or models: they hold a database
        // connection and come back from Redis as __PHP_Incomplete_Class.
        ['rows' => $rows, 'total' => $total] = Cache::remember(
            $this->versionedCacheKey($filters->toCacheKey()),
            config('cache.product_ttl'),
            function () use ($filters): array {
                $page = $this->buildFilteredQuery($filters)->paginate($filters->perPage, page: $filters->page);

                return ['rows' => $page->getCollection()->toArray(), 'total' => $page->total()];
            },
        );

        return new LengthAwarePaginator(
            Product::hydrate($rows),
            $total,
            $filters->perPage,
            $filters->page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    public function find(int $id): ?Product
    {
        $row = Cache::remember(
            $this->versionedCacheKey("product:{$id}"),
            config('cache.product_ttl'),
            fn () => Product::query()->find($id)?->attributesToArray(),
        );

        return $row ? Product::hydrate([$row])->first() : null;
    }

    /**
     * Locks the given products so two orders cannot read the same stock at
     * once. Must run inside a transaction, and is never cached.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    public function lockForOrdering(array $ids): Collection
    {
        return Product::query()->whereIn('id', $ids)->lockForUpdate()->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Product
    {
        $product = Product::query()->create($attributes);

        $this->clearCache();

        return $product;
    }

    /** @param array<string, mixed> $attributes */
    public function update(Product $product, array $attributes): Product
    {
        $product->update($attributes);

        $this->clearCache();

        return $product->refresh();
    }

    public function delete(Product $product): bool
    {
        $deleted = (bool) $product->delete();

        $this->clearCache();

        return $deleted;
    }

    public function reduceStock(Product $product, int $quantity): void
    {
        $product->decrement('stock', $quantity);

        $this->clearCache();
    }

    public function restoreStock(Product $product, int $quantity): void
    {
        $product->increment('stock', $quantity);

        $this->clearCache();
    }

    /** Drops every cached product listing and detail. */
    public function clearCache(): void
    {
        // The counter must exist first: increment() on a missing key returns
        // false without storing anything, which would disable invalidation.
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    private function versionedCacheKey(string $key): string
    {
        return 'v'.Cache::get(self::VERSION_KEY, 1).':'.$key;
    }

    /** @return Builder<Product> */
    private function buildFilteredQuery(ProductFilters $filters): Builder
    {
        return Product::query()
            ->when($filters->isActive !== null, fn (Builder $q) => $q->where('is_active', $filters->isActive))
            ->when($filters->inStock === true, fn (Builder $q) => $q->where('stock', '>', 0))
            ->when($filters->inStock === false, fn (Builder $q) => $q->where('stock', 0))
            ->when($filters->minPrice, fn (Builder $q) => $q->where('price', '>=', $filters->minPrice))
            ->when($filters->maxPrice, fn (Builder $q) => $q->where('price', '<=', $filters->maxPrice))
            ->when($filters->search, fn (Builder $q) => $q->where(function (Builder $q) use ($filters): void {
                // Escape % and _ so a search for "100%" stays a literal search.
                $term = '%'.addcslashes($filters->search, '%_').'%';

                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('description', 'like', $term);
            }))
            ->orderBy($filters->sortBy, $filters->sortDirection);
    }
}
