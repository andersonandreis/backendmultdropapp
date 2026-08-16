<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizacao feita no controller
    }

    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'O campo notas e obrigatorio.',
            'notes.max'      => 'As notas nao podem exceder 5000 caracteres.',
        ];
    }
}
