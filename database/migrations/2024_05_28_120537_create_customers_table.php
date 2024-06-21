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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('name');
            $table->string('contract_no');
            $table->string('nic');
            $table->string('date_of_birth');
            $table->string('civil_status');
            $table->string('contact_no');
            $table->string('address');
            $table->string('email');
            $table->string('centre');
            $table->string('loan_execution_date');
            $table->string('loan_end_date');
            $table->string('remarks');
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
        Schema::dropIfExists('customers');
    }
};
