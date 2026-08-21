<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FareReport extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['mode', 'reported_fare', 'client_hash', 'applied', 'created_at'];

    protected $casts = [
        'reported_fare' => 'float',
        'applied' => 'boolean',
    ];
}
