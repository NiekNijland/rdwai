<?php

declare(strict_types=1);

namespace App\Http\Requests\Rdw;

use App\Models\QueryRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitFeedbackRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', Rule::in([QueryRun::RATING_UP, QueryRun::RATING_DOWN])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
