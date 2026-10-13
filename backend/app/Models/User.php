<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
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
        'avatar_path',
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
        // Raw storage path isn't useful to the frontend and shouldn't be
        // assumed stable — avatar_url (below) is the public contract.
        'avatar_path',
    ];

    /**
     * The accessors to append to the model's array/JSON form.
     *
     * @var list<string>
     */
    protected $appends = ['avatar_url'];

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

    protected static function booted(): void
    {
        // A soft-deleted account keeps its row, and the unique index on `email`
        // would keep the address reserved forever: after an admin deleted a
        // customer's accidental duplicate account, the real customer's email
        // could no longer be corrected (client, Joel, 2026-10-12). Deleting now
        // parks the address so it can be used again.
        static::deleting(function (User $user) {
            if ($user->isForceDeleting()) {
                return;
            }

            $parked = self::parkedEmail($user->id, (string) $user->email);
            if ($parked !== $user->email) {
                $user->forceFill(['email' => $parked])->saveQuietly();
            }
        });
    }

    /**
     * What a deleted account's email becomes: it can never receive mail (the
     * reserved ".invalid" top-level domain does not exist), is unique because
     * it carries the user id, and still shows the original address.
     */
    public static function parkedEmail(int $id, string $email): string
    {
        $suffix = '.invalid';
        if (str_ends_with($email, $suffix)) {
            return $email;
        }

        $prefix = "deleted-{$id}-";

        return $prefix.mb_substr($email, 0, 255 - strlen($prefix) - strlen($suffix)).$suffix;
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path ? Storage::disk('avatars')->url($this->avatar_path) : null,
        );
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
