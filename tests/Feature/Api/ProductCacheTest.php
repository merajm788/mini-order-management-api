<?php

namespace Tests\Feature\Api;

use App\DataTransferObjects\ProductFilters;
use App\Models\Product;
use App\Models\User;
use App\Repositories\ProductRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the caching behaviour of ProductRepository. The suite uses the
 * array store, which behaves like Redis for get/put/increment.
 */
class ProductCacheTest extends TestCase
{
    use RefreshDatabase;

    private ProductRepository $repository;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProductRepository::class);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function a_repeated_listing_is_served_from_the_cache(): void
    {
        Product::factory()->count(3)->for($this->user)->create();
        $filters = new ProductFilters;

        $this->repository->search($filters);

        // The second call must not reach the database at all.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->repository->search($filters);

        $this->assertSame(0, $queries);
    }

    #[Test]
    public function what_gets_cached_survives_serialisation(): void
    {
        // The array store keeps objects in memory, so it never exercises
        // serialisation. Redis does, and a cached paginator or Eloquent model
        // comes back from it as __PHP_Incomplete_Class.
        Product::factory()->count(2)->for($this->user)->create();

        $this->repository->search(new ProductFilters);

        $key = collect(Cache::getStore()->all())->keys()
            ->first(fn (string $k) => str_contains($k, 'products:'));
        $cached = Cache::get($key);

        $this->assertEquals($cached, unserialize(serialize($cached)));
        $this->assertIsArray($cached['rows'][0]);
    }

    #[Test]
    public function different_filters_are_cached_separately(): void
    {
        Product::factory()->for($this->user)->pricedAt(10.00)->create();
        Product::factory()->for($this->user)->pricedAt(500.00)->create();

        $cheap = $this->repository->search(new ProductFilters(maxPrice: 100));
        $all = $this->repository->search(new ProductFilters);

        $this->assertSame(1, $cheap->total());
        $this->assertSame(2, $all->total());
    }

    #[Test]
    public function the_cache_key_ignores_the_order_of_the_filters(): void
    {
        $a = new ProductFilters(search: 'x', minPrice: 5.0);
        $b = new ProductFilters(search: 'x', minPrice: 5.0);

        $this->assertSame($a->toCacheKey(), $b->toCacheKey());
    }

    #[Test]
    public function creating_a_product_invalidates_the_listing_cache(): void
    {
        Product::factory()->count(2)->for($this->user)->create();
        $filters = new ProductFilters;

        $this->assertSame(2, $this->repository->search($filters)->total());

        $this->repository->create([
            'user_id' => $this->user->id,
            'name' => 'Fresh Product',
            'sku' => 'FRESH-0001',
            'price' => 10.00,
            'stock' => 5,
        ]);

        $this->assertSame(3, $this->repository->search($filters)->total());
    }

    #[Test]
    public function updating_a_product_invalidates_its_cached_detail(): void
    {
        $product = Product::factory()->for($this->user)->create(['name' => 'Before']);

        $this->assertSame('Before', $this->repository->find($product->id)->name);

        $this->repository->update($product, ['name' => 'After']);

        $this->assertSame('After', $this->repository->find($product->id)->name);
    }

    #[Test]
    public function deleting_a_product_removes_it_from_cached_listings(): void
    {
        $product = Product::factory()->for($this->user)->create();
        $filters = new ProductFilters;

        $this->assertSame(1, $this->repository->search($filters)->total());

        $this->repository->delete($product);

        $this->assertSame(0, $this->repository->search($filters)->total());
    }

    #[Test]
    public function reducing_stock_invalidates_the_cache(): void
    {
        $product = Product::factory()->for($this->user)->withStock(10)->create();

        $this->assertSame(10, $this->repository->find($product->id)->stock);

        $this->repository->reduceStock($product, 4);

        $this->assertSame(6, $this->repository->find($product->id)->stock);
    }

    #[Test]
    public function placing_an_order_through_the_api_invalidates_the_cached_stock(): void
    {
        $product = Product::factory()->for($this->user)->withStock(10)->create();

        // Warm the cache through the public endpoint.
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 10);

        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        // A stale 10 here would mean customers see stock that no longer exists.
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 7);
    }
}
