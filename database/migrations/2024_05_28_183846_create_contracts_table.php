<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_no');
            $table->string('customer_id');
            $table->string('loan_type');
            $table->string('loan_amount');
            $table->string('cost');
            $table->string('apr');
            $table->string('term');
            $table->string('pay_freq');
            $table->string('due_date');
            $table->string('installments');
            $table->string('total_payment');
            $table->string('total_interest');
            $table->string('def_int_rate');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('contracts');
    }
};
