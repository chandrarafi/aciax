<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StokUnit extends Model
{
    protected $connection = 'pgsql_meta';
    protected $table = 'tblstock_unit';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'no_bpkb',
    ];

    public $timestamps = false;
    
    public function masterCustomer()
    {
        return $this->hasOne(MasterCustomer::class, 'IDCustomer', 'id_an');
    }

}
