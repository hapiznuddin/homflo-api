<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreListExpenseRequest extends FormRequest
{
    /**
     * Expense input. wallet_id/category_id remain optional for backward
     * compatibility with the current UI, which lets the backend resolve
     * the household's active wallet; when supplied they are validated
     * and must belong to the session household.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => ['sometimes', 'string', 'ulid'],
            'category_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'occurred_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'actual_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'type' => ['prohibited'],
            'amount' => ['prohibited'],
            'description' => ['prohibited'],
            'source_type' => ['prohibited'],
            'source_id' => ['prohibited'],
            'household_id' => ['prohibited'],
        ];
    }

    /** Determine if the user is authorized to make this request. */
    public function authorize(): bool
    {
        return true;
    }
}
