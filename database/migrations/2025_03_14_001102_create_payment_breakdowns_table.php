<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->uuid('pymnt_id');
            $table->decimal('overdue_interest', 8, 2);
            $table->decimal('overdue_rent', 8, 2);
            $table->decimal('current_interest', 8, 2);
            $table->decimal('current_rent', 8, 2);
            $table->decimal('future_rent', 8, 2);
            $table->decimal('excess', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_breakdowns');
    }
};
