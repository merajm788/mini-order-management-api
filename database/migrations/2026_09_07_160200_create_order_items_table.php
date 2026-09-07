<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // A product may never be deleted out from under an existing order,
            // hence restrictOnDelete (products use soft deletes anyway).
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Name and price are snapshotted at purchase time: later edits to
            // the product must not rewrite historical invoices.
            $table->string('product_name');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            // One line per product per order; the service merges duplicates.
            $table->unique(['order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
