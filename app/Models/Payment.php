<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'contract_no',
        'payment_amount',
        'payment_date',
        'pymnt_id',
        'receipt_id'
    ];

    /**
     * Get the breakdown associated with the payment.
    */
    public function breakdown()
    {
        return $this->hasOne(PaymentBreakdown::class, 'pymnt_id', 'pymnt_id');
    }
}
