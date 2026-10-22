<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'google_id',
        'email_verified_at',
        'bio',
        'location',
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

    public function ownedWorkspaces()
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /**
     * Build a valid username from a display name or email, adding a suffix
     * when the preferred value is already taken.
     */
    public static function generateUsername(?string $preferred, ?string $email = null): string
    {
        $source = $preferred ?: Str::before((string) $email, '@');
        $base = trim(substr(Str::slug((string) $source, '-'), 0, 26), '-');

        if ($base === '' || strlen($base) < 3) {
            $base = 'user';
        }

        $candidate = $base;
        $suffix = 2;

        while (static::where('username', $candidate)->exists()) {
            $suffixText = '-'.$suffix++;
            $candidate = substr($base, 0, 30 - strlen($suffixText)).$suffixText;
        }

        return $candidate;
    }

    /**
     * The public URL of the uploaded photo, or null when none was uploaded.
     */
    public function photoUrl(): ?string
    {
        return $this->photo_path === null
            ? null
            : route('users.photo', ['user' => $this->getKey()]);
    }

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->using(WorkspaceMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function verificationUrl(): string
    {
        return URL::temporarySignedRoute(
            'auth.verification.verify',
            now()->addMinutes(60),
            [
                'id' => $this->getKey(),
                'hash' => sha1($this->getEmailForVerification()),
            ]
        );
    }
}
