<?php

namespace App\Models;

use App\Notifications\ResetPasswordEs;
use App\Notifications\VerifyEmailEs;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'email',
    'password',
    'avatar_url',
    'cover_url',
    'profession',
    'company',
    'bio',
    'birth_date',
    'interests',
    'portfolio_url'
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

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
            'birth_date' => 'date',
            'interests' => 'array',
        ];
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailEs);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordEs($token));
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Detalles de los que es autor. */
    public function details(): HasMany
    {
        return $this->hasMany(Detail::class, 'author_id');
    }

    /** Usuarios que le dieron "me gusta" a este perfil. */
    public function likesReceived(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_likes', 'to_user_id', 'from_user_id')->withTimestamps();
    }

    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->live()
            ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with('plan')
            ->orderByDesc('starts_at')
            ->first();
    }
}
