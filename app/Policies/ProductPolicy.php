<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /** Inactive products stay visible to their owner only. */
    public function view(?User $user, Product $product): bool
    {
        return $product->is_active || $user?->id === $product->user_id;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }
}
