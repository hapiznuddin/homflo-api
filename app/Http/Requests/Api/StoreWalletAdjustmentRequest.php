<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWalletAdjustmentRequest extends FormRequest
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
     * The amount carries a sign (positive or negative correction) but
     * is never zero: a zero row would be audit noise, not a correction.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:-9999999999999.99', 'max:9999999999999.99', 'not_in:0'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
