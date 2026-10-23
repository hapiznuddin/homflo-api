<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Partial update: omitted keys stay untouched and nullable columns
     * accept an explicit null so a recorded value can be cleared. `name`
     * stays non-nullable because its column is NOT NULL. household_id is
     * never accepted.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'plate_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'odometer' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
