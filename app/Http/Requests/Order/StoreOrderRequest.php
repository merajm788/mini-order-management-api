<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            // 'exists' catches bad ids early; stock and availability are
            // re-checked inside the locked transaction in OrderService.
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'An order must contain at least one item.',
            'items.*.product_id.exists' => 'The selected product does not exist.',
            'items.*.product_id.distinct' => 'Each product may only appear once per order.',
        ];
    }
}
