<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FareReport extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['mode', 'reported_fare', 'client_hash'];

    protected $casts = [
        'reported_fare' => 'float',
        'applied' => 'boolean',
    ];
}
