<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServiceTechnicalConfig;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceTechnicalConfigRequest extends FormRequest
{
    use ValidatesServiceTechnicalConfig;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return $this->technicalConfigRules($this->route('service')?->id, methodRequired: true);
    }

    public function messages(): array
    {
        return $this->technicalConfigMessages();
    }
}
