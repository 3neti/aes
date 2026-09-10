<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ScannerScanEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'station_id',
        'scan_type',
        'source',
        'payload',
        'payload_hash',
        'status',
        'group_id',
        'part_number',
        'total_parts',
        'precinct_id',
        'document_hash',
        'message',
        'metadata',
        'received_at',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
