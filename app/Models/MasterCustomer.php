<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterCustomer extends Model
{
    protected $connection = 'pgsql_nms';
    protected $table = 'mastercustomer';

    public static function getNamaKonsumen(string $nik): ?string
    {
        return static::query()->where('NoKTP', $nik)->value('NamaCustomer');
    }
}
