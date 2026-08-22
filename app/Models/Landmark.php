<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Landmark extends Model
{
    protected $fillable = ['osm_node_id', 'name', 'lat', 'lon', 'poi_type'];

    protected $casts = [
        'lat' => 'float',
        'lon' => 'float',
    ];
}
