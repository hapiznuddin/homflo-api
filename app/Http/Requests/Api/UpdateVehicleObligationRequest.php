<?php

namespace App\Http\Requests\Api;

use App\Models\VehicleObligation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleObligationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Partial update. vehicle_id and household_id are never accepted, so an
     * obligation cannot be moved between vehicles or households. due_date
     * cannot become null when present. Only the two administrative status
     * values are accepted; payment status is not part of this contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'obligation_type_id' => ['sometimes', 'ulid'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'issued_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'status' => ['sometimes', 'string', Rule::in(VehicleObligation::statuses())],
        ];
    }
}
