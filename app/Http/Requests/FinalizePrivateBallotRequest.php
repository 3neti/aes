<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class FinalizePrivateBallotRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'selections' => ['nullable', 'array'],
            'selections.*' => ['array'],
            'selections.*.*' => ['string'],
            'analytics' => ['nullable', 'array'],
            'analytics.session_id' => ['nullable', 'string', 'max:120'],
            'analytics.started_at' => ['nullable', 'string', 'max:80'],
            'analytics.first_selection_at' => ['nullable', 'string', 'max:80'],
            'analytics.last_selection_at' => ['nullable', 'string', 'max:80'],
            'analytics.review_opened_at' => ['nullable', 'string', 'max:80'],
            'analytics.finalized_at' => ['nullable', 'string', 'max:80'],
            'analytics.total_duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'analytics.time_to_first_selection_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'analytics.selection_edit_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'analytics.contest_navigation_clicks' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'analytics.surname_navigation_clicks' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'analytics.review_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'analytics.overvote_attempts_blocked' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'analytics.final_selection_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
