<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListRequest extends FormRequest
{
    /** Get the validation rules that apply to the request. */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
        ];
    }

    /** Determine if the user is authorized to make this request. */
    public function authorize(): bool
    {
        return true;
    }
}