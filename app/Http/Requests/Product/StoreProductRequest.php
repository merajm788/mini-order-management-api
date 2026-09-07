<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may list a product; ownership is only enforced
        // on update and delete, by ProductPolicy.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:products,sku'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'price.decimal' => 'The price may have at most 2 decimal places.',
            'stock.min' => 'Stock cannot be negative.',
        ];
    }
}
