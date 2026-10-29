<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreListExpenseRequest extends FormRequest
{
    /**
     * Expense input per contract §8.3 / §10.7. The wallet is required;
     * category is optional and must be an expense category in the same
     * household. Amount, type, source, description, and household are
     * always server-derived.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => ['required', 'string', 'ulid'],
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
