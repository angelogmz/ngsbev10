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
        Schema::create('master_amortization', function (Blueprint $table) {
            $table->id();
            $table->string('contract_no'); // Foreign key to contracts table
            $table->date('due_date'); // Due date for the installment
            $table->decimal('payment', 15, 2); // Total payment due
            $table->decimal('interest', 15, 2); // Interest portion
            $table->decimal('principal', 15, 2); // Principal portion
            $table->decimal('balance', 15, 2); // Remaining balance
            $table->decimal('balance_interest', 15, 2); // Remaining interest
            $table->decimal('balance_principal', 15, 2); // Remaining principal
            $table->decimal('excess', 15, 2); // Excess payment
            $table->tinyInteger('completed')->default(0); // 0 = incomplete, 1 = completed
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('contract_no')
            ->references('contract_no')
            ->on('contracts')
            ->onDelete('cascade'); // Cascade deletes if the contract is deleted
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_amortization');
    }
};
