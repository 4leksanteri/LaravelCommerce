<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Optional, deliberately. `/search` with no term is "everything on
             * the marketplace, newest first", which is the closest thing this
             * marketplace has to a front page - and it means the search screen
             * works before anybody has typed anything.
             *
             * `min:2` when present: a single character matches nearly the whole
             * catalogue and ranks none of it.
             */
            'q' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],

            /*
             * A slug rather than an id, because it comes from a URL a person can
             * read and share. `exists` is fine here - the category list is
             * public and enumerable by design, so confirming a slug is real
             * reveals nothing.
             */
            'category' => ['sometimes', 'nullable', 'string', 'exists:categories,slug'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.min' => 'Search for at least two characters.',
        ];
    }
}
