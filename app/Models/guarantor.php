<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class guarantor extends Model
{
    use HasFactory;

    protected $table = 'guarantors';

    protected $fillable = [
        'title',
        'name',
        'contract_no',
        'nic',
        'date_of_birth',
        'contact_no',
        'address',
        'email'
    ];
}
