<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function anyone_can_list_products_without_authenticating(): void
    {
        Product::factory()->count(3)->for($this->user)->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'sku', 'price', 'stock', 'in_stock']], 'meta', 'links']);
    }

    #[Test]
    public function listings_hide_inactive_products_by_default(): void
    {
        Product::factory()->count(2)->for($this->user)->create();
        Product::factory()->for($this->user)->inactive()->create();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function products_can_be_searched_by_name_sku_and_description(): void
    {
        Product::factory()->for($this->user)->create(['name' => 'Ergonomic Keyboard']);
        Product::factory()->for($this->user)->create(['name' => 'Wireless Mouse']);

        $this->getJson('/api/v1/products?search=keyboard')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ergonomic Keyboard');
    }

    #[Test]
    public function products_can_be_filtered_by_price_range(): void
    {
        Product::factory()->for($this->user)->pricedAt(10.00)->create();
        Product::factory()->for($this->user)->pricedAt(50.00)->create();
        Product::factory()->for($this->user)->pricedAt(200.00)->create();

        $response = $this->getJson('/api/v1/products?min_price=20&max_price=100')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // JSON drops the trailing .0, so compare numerically rather than by type.
        $this->assertEqualsWithDelta(50.0, $response->json('data.0.price'), 0.001);
    }

    #[Test]
    public function products_can_be_filtered_by_stock_availability(): void
    {
        Product::factory()->count(2)->for($this->user)->withStock(5)->create();
        Product::factory()->for($this->user)->outOfStock()->create();

        $this->getJson('/api/v1/products?in_stock=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/products?in_stock=0')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function products_can_be_sorted(): void
    {
        Product::factory()->for($this->user)->pricedAt(300.00)->create();
        Product::factory()->for($this->user)->pricedAt(100.00)->create();
        Product::factory()->for($this->user)->pricedAt(200.00)->create();

        $response = $this->getJson('/api/v1/products?sort_by=price&sort_direction=asc')->assertOk();

        $this->assertEqualsWithDelta(100.0, $response->json('data.0.price'), 0.001);
        $this->assertEqualsWithDelta(300.0, $response->json('data.2.price'), 0.001);
    }

    #[Test]
    public function an_invalid_sort_column_is_rejected(): void
    {
        // Guards against ordering by an arbitrary, possibly injected, column.
        $this->getJson('/api/v1/products?sort_by=password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort_by');
    }

    #[Test]
    public function a_single_product_can_be_fetched(): void
    {
        $product = Product::factory()->for($this->user)->create();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', $product->name);
    }

    #[Test]
    public function fetching_a_missing_product_returns_a_clean_404(): void
    {
        $this->getJson('/api/v1/products/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Product not found.');
    }

    #[Test]
    public function an_authenticated_user_can_create_a_product(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Standing Desk',
            'description' => 'Electric height adjustable desk.',
            'price' => 499.99,
            'stock' => 12,
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Standing Desk');

        $this->assertEqualsWithDelta(499.99, $response->json('data.price'), 0.001);

        $this->assertDatabaseHas('products', [
            'name' => 'Standing Desk',
            'user_id' => $this->user->id,
            'stock' => 12,
        ]);
    }

    #[Test]
    public function a_sku_is_generated_when_none_is_supplied(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Standing Desk',
            'price' => 499.99,
            'stock' => 12,
        ])->assertCreated();

        $this->assertMatchesRegularExpression(
            '/^STANDING-DESK-[A-Z0-9]{4}$/',
            Product::firstWhere('name', 'Standing Desk')->sku,
        );
    }

    #[Test]
    public function creating_a_product_requires_authentication(): void
    {
        $this->postJson('/api/v1/products', [
            'name' => 'Standing Desk',
            'price' => 499.99,
            'stock' => 12,
        ])->assertUnauthorized();
    }

    #[Test]
    public function product_creation_validates_its_input(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'A',
            'price' => -5,
            'stock' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'price', 'stock']);
    }

    #[Test]
    public function a_price_with_three_decimals_is_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Odd Price',
            'price' => 10.999,
            'stock' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('price');
    }

    #[Test]
    public function an_owner_can_update_their_product(): void
    {
        $product = Product::factory()->for($this->user)->create(['price' => 10.00]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}", ['price' => 25.50, 'stock' => 99])
            ->assertOk()
            ->assertJsonPath('data.stock', 99);

        $this->assertEqualsWithDelta(25.50, $response->json('data.price'), 0.001);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 99]);
    }

    #[Test]
    public function a_user_cannot_update_someone_elses_product(): void
    {
        $product = Product::factory()->for(User::factory())->create();

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}", ['price' => 1.00])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function an_owner_can_delete_their_product(): void
    {
        $product = Product::factory()->for($this->user)->create();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Soft deleted: existing order items keep their foreign key.
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    #[Test]
    public function a_user_cannot_delete_someone_elses_product(): void
    {
        $product = Product::factory()->for(User::factory())->create();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }
}
