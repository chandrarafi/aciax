<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BpkbProcessTrack extends Model
{
    use HasUuids;

    protected $table = 'aciax.bpkb_process_tracks';

    protected $fillable = [
        'no_mesin',
        'no_bpkb',
        'nama_konsumen',
        'pdf_path',
        'image_paths',
        'stage',
        'status',
        'error_message',
        'akurasi',
        'fk_dealer',
        'tgl_mohon_samsat'
    ];

    protected function casts(): array
    {
        return [
            'image_paths' => 'array',
            'tgl_mohon_samsat' => 'date:Y-m-d',
        ];
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public function dealer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Dealer::class, 'fk_dealer', 'kd_dealer_md');
    }

    public function getNmDealerAttribute(): ?string
    {
        return $this->dealer?->nm_dealer;
    }
}
