<?php

namespace App\Http\Requests\Parcel;

use Illuminate\Foundation\Http\FormRequest;

class ParcelRequest extends FormRequest
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
            'filter' => 'nullable|string|in:send,delivery',
            'status' => 'nullable|string|in:active,closed',
            'search' => 'nullable|string|max:255',
        ];
    }

    public function getFilter()
    {
        return $this->validated('filter');
    }

    public function getStatus()
    {
        return $this->validated('status');
    }

    public function getSearch()
    {
        return $this->validated('search');
    }

    /**
     * Get all filter parameters as an array
     */
    public function getFilters(): array
    {
        return [
            'filter' => $this->getFilter(),
            'status' => $this->getStatus(),
            'search' => $this->getSearch(),
        ];
    }
}
