<?php

namespace App\Http\Requests;

use App\Election\Lifecycle\Lifecycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreManualHandoffRecipientVerificationRequest extends FormRequest
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
            'recipient' => ['required', 'string', 'max:140'],
            'recipient_role' => ['required', 'string', 'max:140'],
            'handoff_date' => ['required', 'date_format:Y-m-d'],
            'handoff_time' => ['required', 'date_format:H:i'],
            'delivery_method' => ['required', 'string', 'max:80'],
            'acknowledged' => ['required', 'boolean'],
            'acknowledgement_note' => ['nullable', 'string', 'max:400'],
            'stage' => ['required', 'string', Rule::in(Lifecycle::stages())],
        ];
    }
}
