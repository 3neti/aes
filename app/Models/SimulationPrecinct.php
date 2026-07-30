<?php

namespace App\Models;

use Database\Factories\SimulationPrecinctFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $simulation_round_id
 * @property string $code
 * @property string $clustered_precinct
 * @property string|null $district
 * @property string $label
 * @property string|null $city_municipality
 * @property string|null $province
 * @property string $status
 * @property string $officer_name
 * @property string $officer_code
 * @property string $officer_pin_hash
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property-read SimulationRound $round
 */
#[Fillable([
    'simulation_round_id', 'code', 'clustered_precinct', 'district', 'label',
    'city_municipality', 'province', 'status', 'officer_name', 'officer_code',
    'officer_pin_hash', 'opened_at', 'closed_at',
])]
final class SimulationPrecinct extends Model
{
    /** @use HasFactory<SimulationPrecinctFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return BelongsTo<SimulationRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(SimulationRound::class, 'simulation_round_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
