<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use HasFactory;

    protected $table = 'contracts';

    protected $fillable = [
        'contract_no',
        'customer_id',
        'loan_type',
        'loan_amount',
        'cost',
        'apr',
        'term',
        'term_count',
        'pay_freq',
        'due_date',
        'installments',
        'total_payment',
        'total_interest',
        'def_int_rate',
        'compounding',
        'loan_execution_date',
        'loan_end_date',
        'status',
    ];

    /**
     * Define the many-to-many relationship with the Customer model.
     */
    public function customers()
    {
        return $this->belongsToMany(Customer::class);
    }

    public function amortizationSchedule()
    {
        return $this->hasMany(MasterAmortization::class, 'contract_no', 'contract_no');
    }

}
