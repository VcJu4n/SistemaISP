<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInternetServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id', 'unique:internet_services,client_id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'installation_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'mikrotik_router_id' => ['nullable', 'integer', 'exists:mikrotik_routers,id'],
            'mikrotik_control_method' => ['nullable', Rule::in(['manual', 'pppoe', 'simple_queue', 'dhcp_mac', 'hotspot'])],
            'pppoe_username' => ['nullable', 'required_if:mikrotik_control_method,pppoe', 'string', 'max:100'],
            'pppoe_profile' => ['nullable', 'required_if:mikrotik_control_method,pppoe', 'string', 'max:100'],
            'simple_queue_name' => ['nullable', 'required_if:mikrotik_control_method,simple_queue', 'string', 'max:100'],
            'service_ip_address' => ['nullable', 'required_if:mikrotik_control_method,simple_queue', 'ip'],
            'service_mac_address' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'],
            'client_antenna_ip' => ['nullable', 'ip'],
            'client_antenna_mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'],
            'client_antenna_brand_model' => ['nullable', 'string', 'max:255'],
            'client_antenna_device_name' => ['nullable', 'string', 'max:255'],
            'technical_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.unique' => 'Este cliente ya tiene un servicio asignado.',
            'pppoe_username.required_if' => 'El usuario PPPoE es obligatorio para servicios PPPoE.',
            'pppoe_profile.required_if' => 'El perfil PPPoE es obligatorio para servicios PPPoE.',
            'simple_queue_name.required_if' => 'El nombre de cola es obligatorio para Simple Queue.',
            'service_ip_address.required_if' => 'La IP del cliente es obligatoria para Simple Queue.',
            'service_mac_address.regex' => 'La MAC del servicio debe tener formato valido.',
            'client_antenna_mac.regex' => 'La MAC de la antena debe tener formato valido.',
        ];
    }
}
