<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
    ) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        $products = $this->products->search($request->toFilters());

        return ApiResponse::paginated(ProductResource::collection($products), 'Products retrieved successfully.');
    }

    /**
     * Takes an id rather than a route-model-bound Product, so the lookup goes
     * through the repository's cache instead of hitting the database.
     */
    public function show(Request $request, int $product): JsonResponse
    {
        $model = $this->products->findOrFail($product);

        $this->authorize('view', $model);

        return ApiResponse::success(new ProductResource($model), 'Product retrieved successfully.');
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->user(), $request->validated());

        return ApiResponse::created(new ProductResource($product), 'Product created successfully.');
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $updated = $this->products->update($product, $request->validated());

        return ApiResponse::success(new ProductResource($updated), 'Product updated successfully.');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->products->delete($product);

        return ApiResponse::success(message: 'Product deleted successfully.');
    }
}
