<?php

namespace App\Http\Requests;

use App\Election\Lifecycle\Lifecycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDeliveryReceiptRequest extends FormRequest
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
            'stage' => ['required', 'string', Rule::in(Lifecycle::stages())],
            'delivery_note' => ['nullable', 'string', 'max:400'],
        ];
    }
}
