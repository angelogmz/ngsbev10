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
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('pymnt_id')->unique()->after('id');
            $table->string('receipt_id')->unique()->after('pymnt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop the columns if the migration is rolled back
            $table->dropColumn('pymnt_id');
            $table->dropColumn('receipt_id');
        });
    }
};
