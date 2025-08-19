<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateManualResponseRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'offer_type' => ['required', 'string', Rule::in(['send', 'delivery'])],
            'request_id' => 'required|integer|min:1',
            'message' => 'required|string|max:1000',
            'currency' => 'nullable|string|size:3',
            'amount' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'offer_type.in' => 'The offer type must be either "send" or "delivery".',
            'request_id.min' => 'The request ID must be a positive number.',
            'message.max' => 'The message may not be greater than 1000 characters.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'amount.min' => 'The amount must be a positive number.',
        ];
    }
}
