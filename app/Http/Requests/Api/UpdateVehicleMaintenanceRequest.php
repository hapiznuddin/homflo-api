<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleMaintenanceRequest extends FormRequest
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
     * Partial update: omitted keys stay untouched. vehicle_id, completed_at,
     * and any status field remain server-managed and are never accepted, so
     * a record cannot be moved to another vehicle or have its lifecycle
     * forged. scheduled_date is not nullable when present, matching the
     * required-on-create contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'scheduled_date' => ['sometimes', 'date_format:Y-m-d'],
            'odometer' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
