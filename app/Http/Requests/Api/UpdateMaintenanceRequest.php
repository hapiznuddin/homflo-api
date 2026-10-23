<?php

namespace App\Http\Requests\Api;

use App\Models\Maintenance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaintenanceRequest extends FormRequest
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
     * Partial update: omitted keys stay untouched. household_id,
     * completed_at, and any status field remain server-managed and are
     * never accepted from the payload. When asset_id is supplied it must
     * resolve to an asset in the same household (validated in the
     * service, masked as not found), so a record can never point at a
     * foreign asset. location and scheduled_date are not nullable when
     * present, matching the required-on-create contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'asset_id' => ['sometimes', 'string', 'ulid'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'string', 'max:255'],
            'scheduled_date' => ['sometimes', 'date_format:Y-m-d'],
            'priority' => ['sometimes', 'string', Rule::in([
                Maintenance::PRIORITY_HIGH,
                Maintenance::PRIORITY_MEDIUM,
                Maintenance::PRIORITY_ROUTINE,
            ])],
        ];
    }
}
