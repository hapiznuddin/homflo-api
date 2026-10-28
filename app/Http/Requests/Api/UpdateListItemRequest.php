<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListItemRequest extends FormRequest
{
    /** Get the validation rules that apply to the request. */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|nullable|decimal|between:0,999999999.99',
            'unit' => 'sometimes|nullable|string|max:20',
            'estimated_price' => 'sometimes|nullable|decimal|between:0,9999999999999.99',
            'actual_price' => 'sometimes|nullable|decimal|between:0,9999999999999.99',
        ];
    }

    /** Determine if the user is authorized to make this request. */
    public function authorize(): bool
    {
        return true;
    }
}