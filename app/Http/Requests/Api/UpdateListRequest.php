<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Only the name is mutable. `type` is immutable and the remaining
     * lifecycle fields are server-owned.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['prohibited'],
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
        return true;
    }
}
