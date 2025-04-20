<?php

namespace App\Http\Requests\Review;

use App\Http\DTO\Review\CreateRequestDTO;
use Illuminate\Foundation\Http\FormRequest;

class CreateRequest extends FormRequest
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
            'user_id' => 'required|integer',
            'text' => 'required|string',
            'rating' => 'required|integer',
        ];
    }

    public function getDTO(): CreateRequestDTO
    {
        return new CreateRequestDTO(
            (int) $this->validated('user_id'),
            $this->validated('text'),
            (int) $this->validated('rating')
        );
    }

}
