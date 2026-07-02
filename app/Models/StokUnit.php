<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StokUnit extends Model
{
    protected $connection = 'pgsql_nms';
    protected $table = 'stokunit';
    public $incrementing = false;
    protected $keyType = 'string';

    public function masterCustomer()
    {
        return $this->hasOne(MasterCustomer::class, 'IDCustomer', 'id_an');
    }


}
