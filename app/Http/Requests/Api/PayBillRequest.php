<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PayBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Pay a bill in full: the client only chooses which wallet pays
     * and optionally when the payment occurred. amount is prohibited —
     * the server derives it from the bill row under lock, so a client
     * can never underpay or overpay. category_id is prohibited: the
     * payment always inherits the bill's category (resolved fail-closed
     * server-side). household_id, source linkage, and type are never
     * accepted — ownership and morph source come from server context
     * only; unknown keys are dropped by validated().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => ['required', 'string', 'ulid'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['prohibited'],
            'category_id' => ['prohibited'],
        ];
    }
}
