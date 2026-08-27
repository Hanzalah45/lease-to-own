<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /** True for any staff account — used wherever "admin or super admin" access applies. */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isSuperAdmin();
    }

    /**
     * Super admin always passes. An admin has every permission by default —
     * admin_permissions is an opt-in *restriction* list, not a grant list:
     * once a super admin adds any row for that admin, they're limited to
     * exactly the permissions listed there.
     */
    public function hasAdminPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (! $this->isAdmin()) {
            return false;
        }

        if (! $this->adminPermissions()->exists()) {
            return true;
        }

        return $this->adminPermissions()->where('permission', $permission)->exists();
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function adminPermissions(): HasMany
    {
        return $this->hasMany(AdminPermission::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'customer_id');
    }

    public function leaseAgreements(): HasMany
    {
        return $this->hasMany(LeaseAgreement::class, 'customer_id');
    }

    public function riskProfile(): HasOne
    {
        return $this->hasOne(RiskProfile::class, 'customer_id');
    }
}
