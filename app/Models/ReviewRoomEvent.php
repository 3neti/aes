<?php

namespace App\Models;

use Database\Factories\ReviewRoomEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property string $review_room_id
 * @property int $sequence
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string|null $previous_hash
 * @property string $event_hash
 * @property Carbon $occurred_at
 */
#[Fillable([
    'review_room_id',
    'sequence',
    'event_type',
    'payload',
    'previous_hash',
    'event_hash',
    'occurred_at',
])]
final class ReviewRoomEvent extends Model
{
    /** @use HasFactory<ReviewRoomEventFactory> */
    use HasFactory;

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
            'sequence' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new RuntimeException('Review room events are append-only.'));
        self::deleting(fn (): never => throw new RuntimeException('Review room events are append-only.'));
    }
}
