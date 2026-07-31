<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesServiceTechnicalConfig
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function technicalConfigRules(?int $serviceId = null, bool $methodRequired = false): array
    {
        $methodRule = $methodRequired ? ['required'] : ['nullable'];
        $controlMethod = $this->input('mikrotik_control_method', 'manual');
        $requiresRouter = in_array($controlMethod, ['pppoe', 'simple_queue'], true);

        return [
            'mikrotik_router_id' => [
                Rule::requiredIf($requiresRouter),
                'nullable',
                'integer',
                Rule::exists('mikrotik_routers', 'id')->where('active', true),
            ],
            'mikrotik_control_method' => [...$methodRule, Rule::in(['manual', 'pppoe', 'simple_queue'])],
            'pppoe_username' => [
                'exclude_unless:mikrotik_control_method,pppoe',
                'required',
                'string',
                'max:100',
                Rule::unique('internet_services', 'pppoe_username')->ignore($serviceId),
            ],
            'pppoe_profile' => ['exclude_unless:mikrotik_control_method,pppoe', 'required', 'string', 'max:100'],
            'simple_queue_name' => [
                'exclude_unless:mikrotik_control_method,simple_queue',
                'required',
                'string',
                'max:100',
                Rule::unique('internet_services', 'simple_queue_name')->ignore($serviceId),
            ],
            'service_ip_address' => [
                'exclude_unless:mikrotik_control_method,simple_queue',
                'required',
                'ip',
                Rule::unique('internet_services', 'service_ip_address')->ignore($serviceId),
            ],
            'service_mac_address' => [
                'nullable',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
                Rule::unique('internet_services', 'service_mac_address')->ignore($serviceId),
            ],
            'client_antenna_ip' => [
                'nullable',
                'ip',
                Rule::unique('internet_services', 'client_antenna_ip')->ignore($serviceId),
            ],
            'client_antenna_mac' => [
                'nullable',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
                Rule::unique('internet_services', 'client_antenna_mac')->ignore($serviceId),
            ],
            'client_antenna_brand_model' => ['nullable', 'string', 'max:255'],
            'client_antenna_device_name' => ['nullable', 'string', 'max:255'],
            'technical_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function technicalConfigMessages(): array
    {
        return [
            'mikrotik_router_id.required' => 'Selecciona el router MikroTik para este metodo de control.',
            'mikrotik_router_id.exists' => 'El router MikroTik seleccionado no existe o esta inactivo.',
            'pppoe_username.required' => 'El usuario PPPoE es obligatorio para servicios PPPoE.',
            'pppoe_username.unique' => 'Este usuario PPPoE ya esta asociado a otro servicio.',
            'pppoe_profile.required' => 'El perfil PPPoE es obligatorio para servicios PPPoE.',
            'simple_queue_name.required' => 'El nombre de cola es obligatorio para Simple Queue.',
            'simple_queue_name.unique' => 'Este nombre de cola ya esta asociado a otro servicio.',
            'service_ip_address.required' => 'La IP del cliente es obligatoria para Simple Queue.',
            'service_ip_address.unique' => 'Esta IP de cliente ya esta asociada a otro servicio.',
            'service_mac_address.regex' => 'La MAC del servicio debe tener formato valido.',
            'service_mac_address.unique' => 'Esta MAC ya esta asociada a otro servicio.',
            'client_antenna_ip.unique' => 'Esta IP de antena ya esta asociada a otro servicio.',
            'client_antenna_mac.regex' => 'La MAC de la antena debe tener formato valido.',
            'client_antenna_mac.unique' => 'Esta MAC de antena ya esta asociada a otro servicio.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeTechnicalConfig(array $data): array
    {
        $fields = [
            'mikrotik_router_id',
            'mikrotik_control_method',
            'pppoe_username',
            'pppoe_profile',
            'simple_queue_name',
            'service_ip_address',
            'service_mac_address',
            'client_antenna_ip',
            'client_antenna_mac',
            'client_antenna_brand_model',
            'client_antenna_device_name',
            'technical_notes',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        $method = $data['mikrotik_control_method'] ?? 'manual';
        $data['mikrotik_control_method'] = $method;

        if ($method === 'manual') {
            $data['mikrotik_router_id'] = null;
            $data['pppoe_username'] = null;
            $data['pppoe_profile'] = null;
            $data['simple_queue_name'] = null;
            $data['service_ip_address'] = null;
        }

        if ($method === 'pppoe') {
            $data['simple_queue_name'] = null;
            $data['service_ip_address'] = null;
        }

        if ($method === 'simple_queue') {
            $data['pppoe_username'] = null;
            $data['pppoe_profile'] = null;
        }

        return $data;
    }
}
