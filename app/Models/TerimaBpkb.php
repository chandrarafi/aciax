<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerimaBpkb extends Model
{
    protected $connection = 'pgsql_meta';
    protected $table = 'tblterima_bpkb';

    protected $fillable =[
        'tgl_bpkb_siap'
    ];
}
