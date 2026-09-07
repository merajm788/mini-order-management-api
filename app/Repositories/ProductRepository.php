<?php

namespace App\Repositories;

use App\DataTransferObjects\ProductFilters;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * All product database access, with a Redis cache in front of the two read
 * paths (listing and single product).
 *
 * Every cache key carries a version number. Instead of deleting keys one by one
 * (impossible: there is one per filter combination), a write bumps the version,
 * which orphans all of the old keys at once.
 */
class ProductRepository
{
    private const string VERSION_KEY = 'products:version';

    public function search(ProductFilters $filters): LengthAwarePaginator
    {
        return Cache::remember(
            $this->versionedCacheKey($filters->toCacheKey()),
            config('cache.product_ttl'),
            fn () => $this->buildFilteredQuery($filters)->paginate($filters->perPage, page: $filters->page),
        );
    }

    public function find(int $id): ?Product
    {
        return Cache::remember(
            $this->versionedCacheKey("product:{$id}"),
            config('cache.product_ttl'),
            fn () => Product::query()->find($id),
        );
    }

    /**
     * Locks the given products so two orders cannot read the same stock at
     * once. Must run inside a transaction.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    public function lockForOrdering(array $ids): Collection
    {
        // Never cached: the whole point is to read the live row.
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

    /** Prefixes a key with the current version, so a bump orphans the old one. */
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
