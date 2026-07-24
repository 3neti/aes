<?php

namespace App\Http\Requests\Election;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StorePrecinctSetupRequest extends FormRequest
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
            'chairperson_code' => ['required', 'string', 'max:80'],
            'chairperson_pin' => ['required', 'digits:6'],
            'poll_clerk_code' => ['required', 'string', 'different:chairperson_code', 'max:80'],
            'poll_clerk_pin' => ['required', 'digits:6'],
            'third_member_code' => ['required', 'string', 'different:chairperson_code', 'different:poll_clerk_code', 'max:80'],
            'device_serial' => ['required', 'string', 'max:120'],
            'printer_serial' => ['required', 'string', 'max:120'],
            'scanner_serial' => ['required', 'string', 'max:120'],
            'ballot_stock_start' => ['required', 'integer', 'min:1'],
            'ballot_stock_end' => ['required', 'integer', 'gte:ballot_stock_start'],
            'ballot_box_id' => ['required', 'string', 'max:120'],
            'custody_envelope_id' => ['required', 'string', 'max:120'],
            'seal_numbers' => ['required', 'string', 'max:500'],
        ];
    }
}
