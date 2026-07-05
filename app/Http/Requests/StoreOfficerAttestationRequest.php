<?php

namespace App\Http\Requests;

use App\Election\Lifecycle\Lifecycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOfficerAttestationRequest extends FormRequest
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
            'ceremony' => ['required', 'string', 'max:160'],
            'officer_code' => ['required', 'string', 'max:80'],
            'officer_pin' => ['required', 'string', 'max:32'],
            'signature_data' => ['required', 'string', 'max:500000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'],
            'stage' => ['required', 'string', Rule::in(Lifecycle::stages())],
            'statement' => ['required', 'string', 'max:500'],
        ];
    }
}
