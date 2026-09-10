<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProductImageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * `image` and `mimes` together, and both are about the file rather
             * than about what the client said.
             *
             * `mimes` resolves the type from the file's own contents through
             * fileinfo - it does not read the Content-Type the browser
             * attached, which is a client-supplied string and therefore a lie
             * waiting to happen (root CLAUDE.md section 11). `image` additionally
             * requires that it decodes as one.
             *
             * The list is what may be uploaded, not what is stored. Everything
             * that gets through here leaves as WebP.
             */
            'image' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', (array) config('images.accepted_mimes')),
                'max:'.(int) config('images.max_upload_kilobytes'),
            ],

            'alt_text' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.max' => 'Images can be up to 2 MB. Larger photographs are resized after upload, not before.',
            'image.mimes' => 'Upload a JPEG, PNG or WebP.',
        ];
    }
}
