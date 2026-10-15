<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexBudgetRequest extends FormRequest
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
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    /**
     * @return array{year?: int, month?: int}
     */
    public function filters(): array
    {
        return array_filter($this->only([
            'year',
            'month',
        ]), fn (mixed $value) => $value !== null);
    }
}
