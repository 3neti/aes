<?php

namespace App\Http\Requests;

use App\Election\Lifecycle\Lifecycle;
use App\Election\Voting\SpecialPollingIntakeService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSpecialPollingIntakeRequest extends FormRequest
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
            'intake_type' => [
                'required',
                'string',
                Rule::in(collect(SpecialPollingIntakeService::TYPES)->pluck('value')->all()),
            ],
            'ballot_count' => ['required', 'integer', 'min:1', 'max:2000'],
            'received_from' => ['required', 'string', 'max:160'],
            'received_by' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:400'],
            'stage' => ['required', 'string', Rule::in(Lifecycle::stages())],
        ];
    }
}
