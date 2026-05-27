<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authorization\Permissions;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::USER_VIEW), 403);

        $users = User::query()
            ->with(['defaultBranch', 'roles'])
            ->orderBy('name')
            ->paginate(perPage: (int) $request->query('per_page', 20));

        // Devolver el ResourceCollection directamente: Laravel agrega
        // automáticamente los meta de paginación (current_page, total, etc.)
        return UserResource::collection($users);
    }

    /**
     * GET /api/v1/admin/users/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::USER_VIEW), 403);

        $user = User::query()
            ->with(['defaultBranch', 'branches', 'roles'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * POST /api/v1/admin/users/{uuid}/roles
     *
     * Body: { roles: ["admin", "cajero"] }
     */
    public function syncRoles(Request $request, string $uuid): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::USER_ROLE_ASSIGN), 403);

        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $user = User::where('uuid', $uuid)->firstOrFail();

        // syncRoles del trait HasRoles ya respeta el team_id (company_id)
        // del PermissionsTeamResolver custom.
        $user->syncRoles($validated['roles']);
        $user->load('roles');

        return response()->json(['data' => new UserResource($user)]);
    }
}
