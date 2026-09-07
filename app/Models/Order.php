<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $order_number
 * @property OrderStatus $status
 * @property string $total_amount
 * @property string|null $notes
 */
#[Fillable(['user_id', 'order_number', 'status', 'total_amount', 'notes'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Human-facing reference such as ORD-20260907-4F2A9C. */
    public static function generateNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
    }
}
