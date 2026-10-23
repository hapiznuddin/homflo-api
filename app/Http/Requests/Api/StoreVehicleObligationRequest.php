<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleObligationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * vehicle_id and household_id are never accepted: the vehicle comes from
     * the nested route after server-side household resolution. status is
     * server-managed on create (always Active). amount is optional and may
     * not be negative.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'obligation_type_id' => ['required', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'issued_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
