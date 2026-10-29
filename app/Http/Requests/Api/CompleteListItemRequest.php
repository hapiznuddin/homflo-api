<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CompleteListItemRequest extends FormRequest
{
    /**
     * Completion accepts only the optional actual_price snapshot. Every
     * lifecycle field is derived server-side.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'actual_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'title' => ['prohibited'],
            'quantity' => ['prohibited'],
            'unit' => ['prohibited'],
            'estimated_price' => ['prohibited'],
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
