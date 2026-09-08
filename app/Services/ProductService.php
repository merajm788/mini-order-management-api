<?php

namespace App\Services;

use App\DataTransferObjects\ProductFilters;
use App\Jobs\CancelOrdersForDeletedProduct;
use App\Models\Product;
use App\Models\User;
use App\Repositories\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    public function search(ProductFilters $filters): LengthAwarePaginator
    {
        return $this->products->search($filters);
    }

    public function findOrFail(int $id): Product
    {
        return $this->products->find($id)
            ?? throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
    }

    /** @param array<string, mixed> $data */
    public function create(User $owner, array $data): Product
    {
        $data['user_id'] = $owner->id;
        $data['sku'] ??= $this->generateUniqueSku($data['name']);

        return $this->products->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data): Product
    {
        return $this->products->update($product, $data);
    }

    public function delete(Product $product): bool
    {
        $deleted = $this->products->delete($product);

        CancelOrdersForDeletedProduct::dispatch($product->id)->afterCommit();

        return $deleted;
    }

    /** Builds a readable, unique SKU such as WIRELESS-MOUSE-8F3A. */
    private function generateUniqueSku(string $name): string
    {
        $base = Str::upper(Str::slug(Str::limit($name, 20, '')));

        do {
            $sku = $base.'-'.Str::upper(Str::random(4));
        } while (Product::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }
}
