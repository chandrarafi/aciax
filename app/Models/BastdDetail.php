<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BastdDetail extends Model
{
    /**
     * Database connection untuk tabel BASTD detail.
     *
     * @var string
     */
    protected $connection = 'pgsql_nms';

    /**
     * Nama tabel dengan schema data_unit.
     *
     * @var string
     */
    protected $table = 'data_unit.tblbastd_detail';

    /**
     * Tabel ini tidak memiliki auto-increment primary key standar.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Nonaktifkan timestamp otomatis (created_at, updated_at).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Mass assignable attributes.
     *
     * @var array
     */
    protected $guarded = [];
}
