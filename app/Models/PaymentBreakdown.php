<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentBreakdown extends Model
{
    use HasFactory;

        /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'payment_breakdowns';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'pymnt_id',
        'contract_no',
        'overdue_interest',
        'overdue_rent',
        'current_interest',
        'current_rent',
        'future_rent',
        'excess',
    ];

    /**
     * Get the payment associated with the breakdown.
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class, 'pymnt_id', 'pymnt_id');
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_no', 'contract_no');
    }
}
