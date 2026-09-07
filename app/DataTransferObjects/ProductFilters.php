<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;

/**
 * The query string of GET /products, parsed once into a typed object so the
 * repository never touches HTTP and the cache key is a pure function of the
 * filter values.
 */
final readonly class ProductFilters
{
    /** Columns a client is allowed to sort by. */
    public const array SORTABLE = ['name', 'price', 'stock', 'created_at'];

    public function __construct(
        public ?string $search = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public ?bool $inStock = null,
        public ?bool $isActive = true,
        public string $sortBy = 'created_at',
        public string $sortDirection = 'desc',
        public int $perPage = 15,
        public int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: $request->string('search')->trim()->value() ?: null,
            minPrice: $request->has('min_price') ? $request->float('min_price') : null,
            maxPrice: $request->has('max_price') ? $request->float('max_price') : null,
            inStock: $request->has('in_stock') ? $request->boolean('in_stock') : null,
            // Absent means "active only": inactive products must never leak
            // into the public catalogue. Pass is_active=0 to see them.
            isActive: $request->has('is_active') ? $request->boolean('is_active') : true,
            sortBy: $request->input('sort_by', 'created_at'),
            sortDirection: $request->input('sort_direction', 'desc'),
            perPage: $request->integer('per_page', 15),
            page: $request->integer('page', 1),
        );
    }

    /** Identical filters always produce the same key, whatever their order. */
    public function toCacheKey(): string
    {
        return 'products:'.md5(serialize(get_object_vars($this)));
    }
}
