<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * A small hand-written catalogue makes the search filters demonstrable
     * (predictable names, a spread of prices, one out-of-stock, one inactive).
     *
     * @var array<int, array{name: string, price: float, stock: int, description: string}>
     */
    private const CATALOGUE = [
        ['name' => 'Wireless Mouse', 'price' => 24.99, 'stock' => 150, 'description' => 'Ergonomic 2.4GHz wireless mouse with silent clicks.'],
        ['name' => 'Mechanical Keyboard', 'price' => 89.50, 'stock' => 60, 'description' => 'Hot-swappable 87-key board with brown switches.'],
        ['name' => 'USB-C Hub', 'price' => 39.00, 'stock' => 90, 'description' => '7-in-1 hub with HDMI, ethernet and 100W passthrough.'],
        ['name' => '27 Inch 4K Monitor', 'price' => 349.99, 'stock' => 25, 'description' => 'IPS panel, 99% sRGB, height adjustable stand.'],
        ['name' => 'Noise Cancelling Headphones', 'price' => 199.00, 'stock' => 40, 'description' => 'Over-ear ANC headphones with 30 hour battery.'],
        ['name' => 'Laptop Stand', 'price' => 45.00, 'stock' => 120, 'description' => 'Aluminium riser, adjustable to six heights.'],
        ['name' => 'Webcam 1080p', 'price' => 59.99, 'stock' => 0, 'description' => 'Full HD webcam with dual noise-cancelling mics.'],
        ['name' => 'Portable SSD 1TB', 'price' => 129.00, 'stock' => 75, 'description' => 'USB 3.2 Gen 2, up to 1050MB/s read.'],
        ['name' => 'Desk Lamp', 'price' => 32.50, 'stock' => 200, 'description' => 'Dimmable LED lamp with three colour temperatures.'],
        ['name' => 'Cable Organiser Set', 'price' => 12.99, 'stock' => 300, 'description' => 'Ten reusable velcro straps and six adhesive clips.'],
    ];

    public function run(): void
    {
        $merchant = User::query()->where('email', 'demo@example.com')->firstOrFail();

        foreach (self::CATALOGUE as $item) {
            Product::query()->updateOrCreate(
                ['sku' => Str::upper(Str::slug($item['name']))],
                [
                    'user_id' => $merchant->id,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'price' => $item['price'],
                    'stock' => $item['stock'],
                    'is_active' => true,
                ],
            );
        }

        // One inactive product, to prove it is filtered out of listings and
        // rejected when ordered.
        Product::query()->updateOrCreate(
            ['sku' => 'DISCONTINUED-TABLET-STAND'],
            [
                'user_id' => $merchant->id,
                'name' => 'Discontinued Tablet Stand',
                'description' => 'No longer sold; retained for order history.',
                'price' => 19.99,
                'stock' => 5,
                'is_active' => false,
            ],
        );

        // Filler owned by other merchants, so ownership checks have something
        // to fail against.
        User::query()
            ->whereNotIn('email', ['demo@example.com', 'customer@example.com'])
            ->take(4)
            ->get()
            ->each(fn (User $user) => Product::factory()->count(3)->for($user)->create());
    }
}
