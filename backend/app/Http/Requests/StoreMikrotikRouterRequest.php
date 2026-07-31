<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMikrotikRouterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:mikrotik_routers,name'],
            'ip_address' => ['required', 'ip', Rule::unique('mikrotik_routers', 'ip_address')->where(fn ($query) => $query->where('api_port', $this->integer('api_port', 8728)))],
            'api_port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
            'use_ssl' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
