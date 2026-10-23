<?php

namespace App\Http\Requests\Api;

use App\Models\Maintenance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRequest extends FormRequest
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
     * household_id, completed_at, and any status field are never accepted:
     * ownership and lifecycle come from the server-resolved household
     * context and the Complete action only. asset_id is required because
     * household maintenance always belongs to an asset, even though the
     * column stays nullable for the ON DELETE SET NULL detach rule.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'string', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['required', 'string', 'max:255'],
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'priority' => ['required', 'string', Rule::in([
                Maintenance::PRIORITY_HIGH,
                Maintenance::PRIORITY_MEDIUM,
                Maintenance::PRIORITY_ROUTINE,
            ])],
        ];
    }
}
