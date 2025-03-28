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
        Schema::table('master_amortization', function (Blueprint $table) {
            $table->dropColumn('balance_interest');
            $table->dropColumn('balance_principal');
            $table->decimal('balance_payment', 15, 2)->after('balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_amortization', function (Blueprint $table) {
            //
        });
    }
};
