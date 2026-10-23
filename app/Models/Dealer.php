<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dealer extends Model
{
    protected $connection = 'pgsql_nms';

    protected $table = 'public.tbldealer';

    protected $primaryKey = 'kd_dealer_md';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
