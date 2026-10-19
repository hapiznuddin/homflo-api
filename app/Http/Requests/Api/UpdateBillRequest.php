<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Partial update of one bill. is_active is prohibited (paid bills
     * archive through POST /bills/{bill}/archive only) and any
     * recurrence key is prohibited (D-07: schedule changes go through
     * PATCH /bills/recurrences/{recurrence} only).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'category_id' => ['sometimes', 'nullable', 'ulid'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['prohibited'],
            'recurrence' => ['prohibited'],
        ];
    }
}
