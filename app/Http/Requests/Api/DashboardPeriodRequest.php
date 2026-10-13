<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DashboardPeriodRequest extends FormRequest
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
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    /**
     * Resolve the requested period. Missing values fall back to the current
     * month in the application timezone (UTC, see config/app.php).
     *
     * @return array{month: int, year: int}
     */
    public function period(): array
    {
        $validated = $this->validated();

        return [
            'month' => isset($validated['month']) ? (int) $validated['month'] : now()->month,
            'year' => isset($validated['year']) ? (int) $validated['year'] : now()->year,
        ];
    }
}
