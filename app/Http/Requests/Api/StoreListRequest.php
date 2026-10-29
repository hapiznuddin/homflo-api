<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreListRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `type` is required and immutable after creation; the contract only
     * allows shopping and custom lists. Server-owned fields are rejected
     * instead of silently ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:shopping,custom'],
            'household_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'is_default' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }

    /** Determine if the user is authorized to make this request. */
    public function authorize(): bool
    {
        // Authentication, verification, and household membership are
        // enforced by middleware and the controller's session scope.
        return true;
    }
}
