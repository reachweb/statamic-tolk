<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('translate content') || $user->can('run bulk translations');
    }
}
