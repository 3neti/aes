<?php

namespace App\Http\Requests\Election;

use Illuminate\Foundation\Http\FormRequest;

final class CreateReviewRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return config('election.review.enabled', false)
            && config('election.review_room.enabled', false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'voter_stations' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('election.review_room.max_voter_stations', 10),
            ],
        ];
    }
}
