<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexTransactionRequest extends FormRequest
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
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'type' => ['sometimes', 'nullable', 'string', 'in:income,expense'],
            'sort_by' => ['sometimes', 'nullable', 'string', 'in:occurred_on,amount,created_at'],
            'sort_dir' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{date_from?: string, date_to?: string, category_id?: string, type?: string}
     */
    public function filters(): array
    {
        return array_filter($this->only([
            'date_from',
            'date_to',
            'category_id',
            'type',
        ]), fn (mixed $value) => $value !== null);
    }

    /**
     * @return array{sort_by: string, sort_dir: string, per_page: int}
     */
    public function listing(): array
    {
        $validated = $this->validated();

        return [
            'sort_by' => $validated['sort_by'] ?? 'occurred_on',
            'sort_dir' => $validated['sort_dir'] ?? 'desc',
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : 15,
        ];
    }
}
