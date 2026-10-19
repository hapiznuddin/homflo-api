<?php

namespace App\Http\Requests\Api;

use App\Models\BillRecurrence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * household_id is never accepted: membership and household scoping
     * come from the server-side session context only. recurrence is a
     * schedule object (not a relation id); sending it turns the request
     * into a schedule + twelve materialized periods (D-01/D-02) and
     * requires due_date to seed the calendar.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'required_with:recurrence'],
            'category_id' => ['sometimes', 'nullable', 'ulid'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'recurrence' => ['sometimes', 'nullable', 'array'],
            'recurrence.frequency' => [
                'required_with:recurrence',
                'string',
                Rule::in(BillRecurrence::FREQUENCIES),
            ],
        ];
    }
}
