<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterAmortization extends Model
{
    use HasFactory;

    protected $table = 'master_amortization'; // Specify the table name
    protected $primaryKey = 'id';
    protected $fillable = [
        'contract_no',
        'due_date',
        'payment',
        'interest',
        'principal',
        'balance',
        'balance_payment',
        'balance_interest',
        'balance_principal',
        'excess',
        'completed',
    ];

    /**
     * Get the contract associated with the amortization schedule.
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_no', 'contract_no');
    }
}
