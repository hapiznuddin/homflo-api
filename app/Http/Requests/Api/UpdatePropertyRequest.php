<?php

namespace App\Http\Requests\Api;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['house', 'apartment', 'land', 'shophouse', 'other'])],
            'address' => ['sometimes', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'ownership_status' => ['sometimes', 'string', Rule::in(['owned', 'rented'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}