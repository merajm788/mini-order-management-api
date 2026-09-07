<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ProductPolicy::update runs in the controller.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            // Ignore this product's own row, so re-saving an unchanged SKU does
            // not trip the unique rule.
            'sku' => ['sometimes', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
