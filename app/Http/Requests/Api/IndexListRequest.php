<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class IndexListRequest extends FormRequest
{
    /**
     * Query strings deliver "true"/"false" as strings, which Laravel's
     * boolean rule rejects; normalize them while leaving genuinely
     * invalid values (e.g. "maybe") to fail the rule with a 422.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('include_archived')) {
            return;
        }

        $this->merge([
            'include_archived' => filter_var(
                $this->input('include_archived'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? $this->input('include_archived'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'in:shopping,custom'],
            'include_archived' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalized list filters. Archived lists stay hidden unless the
     * caller explicitly asks for them.
     *
     * @return array{include_archived: bool, type: ?string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'include_archived' => (bool) ($validated['include_archived'] ?? false),
            'type' => $validated['type'] ?? null,
        ];
    }

    /** Determine if the user is authorized to make this request. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
