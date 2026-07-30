<?php

namespace App\Models;

use Database\Factories\SimulationRoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property Carbon $opened_at
 * @property Carbon|null $archived_at
 */
#[Fillable(['code', 'name', 'status', 'opened_at', 'archived_at'])]
final class SimulationRound extends Model
{
    /** @use HasFactory<SimulationRoundFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return HasMany<SimulationPrecinct, $this>
     */
    public function precincts(): HasMany
    {
        return $this->hasMany(SimulationPrecinct::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
