<?php

namespace App\Http\Requests\Response;

use Illuminate\Foundation\Http\FormRequest;

class CreateManualResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'offer_type' => 'required|in:send,delivery',
            'request_id' => 'required|integer',
            'message' => 'required|string',
            'currency' => 'nullable|string',
            'amount' => 'nullable|integer',
        ];
    }
}