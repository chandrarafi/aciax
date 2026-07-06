<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerimaBpkb extends Model
{
    protected $connection = 'pgsql_nms';
    protected $table = 'data_unit.tblterima_bpkb';

    protected $fillable =[
        'tgl_bpkb_siap'
    ];
}
