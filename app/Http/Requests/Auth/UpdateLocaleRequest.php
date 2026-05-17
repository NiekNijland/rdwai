<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ];
    }

    public function resolvedLocale(): Locale
    {
        /** @var array{locale: string} $validated */
        $validated = $this->validated();

        return Locale::from($validated['locale']);
    }
}
