<?php

declare(strict_types=1);

namespace App\Http\Requests;

class SetPresentationJoinQrRequest extends PresentationCommandRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return parent::rules() + ['show_join_qr' => ['required', 'boolean']];
    }
}
