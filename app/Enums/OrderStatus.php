<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Only orders that have not shipped yet can be cancelled. */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }
}
