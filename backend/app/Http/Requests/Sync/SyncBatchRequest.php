<?php
declare(strict_types=1);
namespace App\Http\Requests\Sync;

use Illuminate\Foundation\Http\FormRequest;

class SyncBatchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'batch_uuid'              => ['required', 'uuid'],
            'items'                   => ['required', 'array', 'min:1', 'max:50'],
            'items.*.client_uuid'     => ['required', 'uuid'],
            'items.*.entity_type'     => ['required', 'string', 'in:sale'],
            'items.*.entity_uuid'     => ['required', 'uuid'],
            'items.*.operation'       => ['required', 'string', 'in:create,update,delete'],
            'items.*.payload'         => ['required', 'array'],
            'items.*.client_timestamp'=> ['required', 'date'],
        ];
    }
}
