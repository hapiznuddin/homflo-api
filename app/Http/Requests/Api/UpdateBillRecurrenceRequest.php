<?php

namespace App\Http\Requests\Api;

use App\Models\BillRecurrence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillRecurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Partial update of one schedule definition (D-07). due_day 1-31
     * clamps to the end of any short target month; frequency accepts
     * only the four MVP values (D-01). Changes apply to unpaid and
     * unarchived periods only (D-03), and frequency changes are
     * forward-only from the cursor (D-09). There is no DELETE endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'category_id' => ['sometimes', 'nullable', 'ulid'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'due_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'frequency' => ['sometimes', 'string', Rule::in(BillRecurrence::FREQUENCIES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
