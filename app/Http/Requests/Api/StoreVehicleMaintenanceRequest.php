<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleMaintenanceRequest extends FormRequest
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
     * vehicle_id is never accepted: the vehicle comes from the nested route
     * resolved inside the server-side household context. completed_at and
     * any status field are server-managed through the Complete action, so a
     * fresh record is always Scheduled. scheduled_date is required, matching
     * the household maintenance contract and the (vehicle_id,
     * scheduled_date) index. Odometer is a non-negative integer.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'odometer' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
