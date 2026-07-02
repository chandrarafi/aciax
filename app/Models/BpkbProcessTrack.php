<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BpkbProcessTrack extends Model
{
    use HasUuids;

    protected $table = 'bpkb_process_tracks';

    protected $fillable = [
        'no_mesin',
        'no_bpkb',
        'nama_konsumen',
        'pdf_path',
        'image_paths',
        'stage',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'image_paths' => 'array',
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
}
