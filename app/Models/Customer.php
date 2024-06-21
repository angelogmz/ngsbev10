<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'customers';

    protected $fillable = [
        'title',
        'name',
        'contract_no',
        'nic',
        'date_of_birth',
        'civil_status',
        'contact_no',
        'address',
        'email',
        'centre',
        'loan_execution_date',
        'loan_end_date',
        'remarks',
    ];

    /**
     * Define the many-to-many relationship with the Contract model.
     */
    public function contracts()
    {
        return $this->belongsToMany(Contract::class);
    }
}
