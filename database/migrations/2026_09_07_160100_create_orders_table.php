<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Orders outlive their customer only if we choose so; here a deleted
            // user takes their order history with them.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->string('status')->default(OrderStatus::Pending->value);
            $table->decimal('total_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            // "My orders, newest first" is the hot query on this table.
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
