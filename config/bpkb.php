<?php

return [
    /*
    |--------------------------------------------------------------------------
    | BPKB Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for BPKB processing, including daily processing targets.
    |
    */

    'daily_target' => (int) env('BPKB_DAILY_TARGET', 100),
];
