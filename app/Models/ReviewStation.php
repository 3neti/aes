<?php

namespace App\Models;

use App\Election\ReviewRoom\ReviewStationRole;
use Database\Factories\ReviewStationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $review_room_id
 * @property ReviewStationRole $role
 * @property string $label
 * @property int $slot
 * @property string $join_token
 * @property string $join_token_hash
 * @property string|null $session_id_hash
 * @property Carbon|null $joined_at
 * @property Carbon|null $last_seen_at
 * @property-read ReviewRoom $room
 */
#[Fillable([
    'review_room_id',
    'role',
    'label',
    'slot',
    'join_token',
    'join_token_hash',
    'session_id_hash',
    'joined_at',
    'last_seen_at',
])]
#[Hidden(['join_token', 'join_token_hash', 'session_id_hash'])]
final class ReviewStation extends Model
{
    /** @use HasFactory<ReviewStationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<ReviewRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ReviewRoom::class, 'review_room_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ReviewStationRole::class,
            'slot' => 'integer',
            'join_token' => 'encrypted',
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
