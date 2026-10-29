<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999.99'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'estimated_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'actual_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'status' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'completed_by' => ['prohibited'],
            'created_by' => ['prohibited'],
            'list_id' => ['prohibited'],
            'household_id' => ['prohibited'],
        ];
    }

    /** Determine if the user is authorized to make this request. */
    public function authorize(): bool
    {
        return true;
    }
}
