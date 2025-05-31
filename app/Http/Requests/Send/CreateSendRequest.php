<?php

namespace App\Http\Requests\Send;

use App\Http\DTO\SendRequest\CreateRequestDTO;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class CreateSendRequest extends FormRequest
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
            'from_location' => 'required|string',
            'to_location' => 'required|string',
            'description' => 'nullable|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date',
            'price' => 'nullable|integer',
            'currency' => 'nullable|string'
        ];
    }

    public function getDTO(): CreateRequestDTO
    {
        return new CreateRequestDTO(
            $this->validated('from_location'),
            $this->validated('to_location'),
            $this->validated('description'),
            CarbonImmutable::parse($this->validated('from_date')),
            CarbonImmutable::parse($this->validated('to_date')),
            $this->validated('price'),
            $this->validated('currency'),
        );
    }

}
