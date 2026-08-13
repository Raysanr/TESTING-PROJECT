<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Phase 1 stub fare table
    |--------------------------------------------------------------------------
    |
    | Flat, simplified per-mode base fares for display-only fare estimates.
    | Not the legally-accurate LTFRB distance/discount matrix — that's Phase 2
    | (Group B: Cost Intelligence, see aboutus.md).
    |
    */

    'jeepney' => 13.00,
    'bus' => 13.00,
    'lrt' => 20.00,
    'mrt' => 20.00,
    'default' => 15.00,
];
