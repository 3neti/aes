<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ReconcileVvpatPrintRequest extends FormRequest
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
            'officer_code' => ['required', 'string', 'max:64'],
            'officer_pin' => ['required', 'string', 'max:64'],
            'reconciliation_token' => ['required', 'string', 'size:64'],
            'no_usable_paper' => ['accepted'],
            'queue_and_buffer_cleared' => ['accepted'],
        ];
    }
}
