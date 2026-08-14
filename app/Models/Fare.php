<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fare extends Model
{
    protected $fillable = ['mode', 'base_fare'];

    protected $casts = [
        'base_fare' => 'float',
    ];
}
