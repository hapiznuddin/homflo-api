<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
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
     * household_id is never accepted: ownership comes from the
     * server-resolved household context only. Only `name` is required; the
     * other Vehicle Information fields mirror the nullable columns of the
     * `vehicles` table. The `type` field is a free-form vehicle category
     * (no enum is fixed by the product decision). Odometer is a
     * non-negative integer capped at the signed 32-bit range the column
     * accepts on PostgreSQL.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'plate_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'odometer' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
