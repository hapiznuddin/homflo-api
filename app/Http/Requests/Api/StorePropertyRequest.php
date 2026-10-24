<?php

namespace App\Http\Requests\Api;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['house', 'apartment', 'land', 'shophouse', 'other'])],
            'address' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ownership_status' => ['required', 'string', Rule::in(['owned', 'rented'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}