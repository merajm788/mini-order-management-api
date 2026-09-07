<?php

namespace Database\Seeders;

use App\Repositories\ProductRepository;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(ProductRepository $products): void
    {
        // Order matters: products need a merchant, orders need products.
        $this->call([
            UserSeeder::class,
            ProductSeeder::class,
            OrderSeeder::class,
        ]);

        // Seeders write rows behind the repository's back, so drop any product
        // listings cached from a previous run.
        $products->clearCache();
    }
}
