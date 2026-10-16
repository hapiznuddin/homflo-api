<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
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
            'type' => ['sometimes', 'string', 'in:income,expense,transfer'],
            'category_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'wallet_id' => ['sometimes', 'string', 'ulid'],
            'destination_wallet_id' => ['sometimes', 'nullable', 'string', 'ulid', 'different:wallet_id'],
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999.99'],
            'occurred_on' => ['sometimes', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'wallet' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
