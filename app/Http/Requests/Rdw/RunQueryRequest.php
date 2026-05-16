<?php

declare(strict_types=1);

namespace App\Http\Requests\Rdw;

use Illuminate\Foundation\Http\FormRequest;

final class RunQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
