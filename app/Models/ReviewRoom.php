<?php

namespace App\Models;

use Database\Factories\ReviewRoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $precinct_id
 * @property string|null $run_id
 * @property string $status
 * @property int $voter_station_count
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 */
#[Fillable([
    'code',
    'name',
    'precinct_id',
    'run_id',
    'status',
    'voter_station_count',
    'opened_at',
    'closed_at',
])]
final class ReviewRoom extends Model
{
    /** @use HasFactory<ReviewRoomFactory> */
    use HasFactory, HasUuids;

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return HasMany<ReviewStation, $this>
     */
    public function stations(): HasMany
    {
        return $this->hasMany(ReviewStation::class);
    }

    /**
     * @return HasMany<ReviewRoomEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ReviewRoomEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'voter_station_count' => 'integer',
        ];
    }
}
