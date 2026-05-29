<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'two_factor_enabled' => $this->two_factor_enabled,
            'has_pin' => $this->pin_hash !== null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'default_branch' => $this->whenLoaded('defaultBranch', function () {
                /** @var \App\Domain\Tenancy\Models\Branch $branch */
                $branch = $this->defaultBranch;
                return [
                    'uuid' => $branch->uuid,
                    'code' => $branch->code,
                    'name' => $branch->name,
                    // Acceso directo al atributo: Laravel devuelve la
                    // relacion cacheada si fue eager-loaded por el caller,
                    // o lazy-loadea sino. Asi el response del login y el
                    // de /auth/me son siempre consistentes sin depender de
                    // que el caller haya llamado $user->load(...).
                    'default_warehouse_uuid' => $branch->defaultWarehouse?->uuid,
                ];
            }),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($b) => [
                'uuid' => $b->uuid,
                'code' => $b->code,
                'name' => $b->name,
            ])),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->when(
                $request->boolean('include_permissions'),
                fn () => $this->getAllPermissions()->pluck('name')
            ),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
