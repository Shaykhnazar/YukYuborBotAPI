<?php

namespace App\Http\Requests\Send;

use App\Http\DTO\SendRequest\CreateSendRequestDTO;
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
            'from_location_id' => 'required|integer|exists:locations,id',
            'to_location_id' => 'required|integer|exists:locations,id',
            'description' => 'nullable|string',
            'to_date' => 'required|date',
            'price' => 'nullable|integer',
            'currency' => 'nullable|string'
        ];
    }

    public function getDTO(): CreateSendRequestDTO
    {
        return new CreateSendRequestDTO(
            $this->validated('from_location_id'),
            $this->validated('to_location_id'),
            $this->validated('description'),
            CarbonImmutable::parse($this->validated('to_date')),
            $this->validated('price'),
            $this->validated('currency'),
        );
    }

}
