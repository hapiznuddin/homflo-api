<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:income,expense'],
            'category_id' => ['nullable', 'string', 'ulid'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:5000'],
            'wallet' => ['nullable', 'string', 'max:100'],
        ];
    }
}
