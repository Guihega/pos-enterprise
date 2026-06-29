<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int|null $branch_id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property string $password
 * @property string|null $pin_hash
 * @property bool $is_active
 * @property bool $must_change_password
 * @property bool $two_factor_enabled
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property array<string, mixed> $preferences
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use BelongsToTenant;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'uuid',
        'company_id',
        'branch_id',
        'name',
        'email',
        'username',
        'password',
        'pin_hash',
        'is_active',
        'must_change_password',
        'locale',
        'timezone',
        'preferences',
    ];

    protected $hidden = [
        'password',
        'pin_hash',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'immutable_datetime',
        'pin_set_at' => 'immutable_datetime',
        'pin_locked_until' => 'datetime',
        'two_factor_enabled' => 'boolean',
        'two_factor_confirmed_at' => 'immutable_datetime',
        'two_factor_recovery_codes' => 'array',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'password_changed_at' => 'immutable_datetime',
        'last_login_at' => 'immutable_datetime',
        'locked_until' => 'datetime',
        'preferences' => 'array',
        'password' => 'hashed',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------- Relaciones --------------------

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function branches(): BelongsToMany
    {
        // El pivot user_branches tiene company_id NOT NULL (RLS).
        // Para attach/sync usar el método helper attachBranches() de abajo,
        // que completa el company_id automáticamente.
        return $this->belongsToMany(Branch::class, 'user_branches')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    /**
     * Sincroniza las sucursales del usuario completando company_id en el pivot.
     *
     * Existe porque user_branches.company_id es NOT NULL (RLS) y sync() de
     * Eloquent no completa columnas extra automáticamente.
     *
     * @param  iterable<int>|iterable<Branch>  $branches
     */
    public function syncBranches(iterable $branches): array
    {
        $payload = [];
        foreach ($branches as $branch) {
            $branchId = is_object($branch) ? $branch->id : (int) $branch;
            $payload[$branchId] = ['company_id' => $this->company_id];
        }

        return $this->branches()->sync($payload);
    }

    // -------------------- Lógica de auth --------------------

    /**
     * ¿La cuenta está bloqueada por intentos fallidos?
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * ¿El usuario es operativo? (activo, no bloqueado)
     */
    public function isOperational(): bool
    {
        return $this->is_active && ! $this->isLocked();
    }

    /**
     * Registra intento fallido. Bloquea tras 5 intentos por 15 minutos.
     */
    public function registerFailedLogin(): void
    {
        $this->failed_login_attempts++;

        if ($this->failed_login_attempts >= 5) {
            $this->locked_until = now()->addMinutes(15);
        }

        $this->save();
    }

    /**
     * Limpia contadores tras login exitoso. Registra metadatos del request.
     */
    public function registerSuccessfulLogin(
        string $ip,
        ?string $userAgent = null,
        ?string $deviceId = null,
    ): void {
        $this->failed_login_attempts = 0;
        $this->locked_until = null;
        $this->last_login_at = now();
        $this->last_login_ip = $ip;
        $this->last_login_user_agent = $userAgent ? mb_substr($userAgent, 0, 500) : null;
        $this->last_login_device_id = $deviceId;
        $this->save();
    }

    /**
     * Verifica el PIN supervisor. Devuelve true si coincide, falso si no.
     * Bloquea el PIN tras 10 intentos fallidos.
     */
    public function verifyPin(string $pin): bool
    {
        if ($this->pin_hash === null) {
            return false;
        }

        if ($this->pin_locked_until !== null && $this->pin_locked_until->isFuture()) {
            return false;
        }

        if (! Hash::check($pin, $this->pin_hash)) {
            $this->pin_failed_attempts++;
            if ($this->pin_failed_attempts >= 10) {
                $this->pin_locked_until = now()->addMinutes(15);
                $this->pin_failed_attempts = 0;
            }
            $this->save();

            return false;
        }

        $this->pin_failed_attempts = 0;
        $this->pin_locked_until = null;
        $this->save();

        return true;
    }

    /**
     * Establece o cambia el PIN. Valida formato (4-8 dígitos, no triviales).
     *
     * @throws \InvalidArgumentException
     */
    public function setPin(string $pin): void
    {
        if (! preg_match('/^\d{4,8}$/', $pin)) {
            throw new \InvalidArgumentException('El PIN debe tener entre 4 y 8 dígitos.');
        }

        $trivial = ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777',
            '8888', '9999', '1234', '4321', '0123', '9876'];

        if (in_array($pin, $trivial, true)) {
            throw new \InvalidArgumentException('El PIN es demasiado simple.');
        }

        $this->pin_hash = Hash::make($pin);
        $this->pin_set_at = now();
        $this->pin_failed_attempts = 0;
        $this->pin_locked_until = null;
        $this->save();
    }

    /**
     * Si tiene cuenta de email para login.
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    // -------------------- Factory --------------------

    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }
}
