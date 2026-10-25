<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetRequest extends FormRequest
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
     * server-resolved household context only. Nullable columns accept an
     * explicit null so a recorded value can be cleared.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'property_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'acquired_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'acquisition_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
