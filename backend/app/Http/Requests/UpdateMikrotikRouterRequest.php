<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMikrotikRouterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $router = $this->route('mikrotik_router');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('mikrotik_routers', 'name')->ignore($router)],
            'ip_address' => [
                'required',
                'ip',
                Rule::unique('mikrotik_routers', 'ip_address')
                    ->where(fn ($query) => $query->where('api_port', $this->integer('api_port', $router?->api_port ?? 8728)))
                    ->ignore($router),
            ],
            'api_port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'use_ssl' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ];
    }
}
