<?php

namespace App\Http\Requests\Product;

use App\DataTransferObjects\ProductFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $boolean = ['sometimes', Rule::in(['1', '0', 'true', 'false'])];

        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'gte:min_price'],
            'in_stock' => $boolean,
            'is_active' => $boolean,
            'sort_by' => ['sometimes', Rule::in(ProductFilters::SORTABLE)],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'max_price.gte' => 'The maximum price must be greater than or equal to the minimum price.',
        ];
    }

    /** The validated query string as a typed filter object. */
    public function toFilters(): ProductFilters
    {
        return ProductFilters::fromRequest($this);
    }
}
