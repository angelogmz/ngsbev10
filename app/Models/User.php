<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->username)) {
                $user->username = self::generateUsername($user->name);
            }
        });
    }

    /**
     * Generate a username from the given name.
     *
     * @param string $name
     * @return string
     */
    public static function generateUsername($name)
    {
        // Remove vowels
        $username = preg_replace('/[aeiouAEIOU]/', '', $name);

        // Remove spaces and convert to lowercase
        $username = strtolower(str_replace(' ', '', $username));

        // Shuffle the characters
        // $username = str_shuffle($username);

        // Trim to the first 6 characters
        $username = substr($username, 0, 6);

        // Ensure uniqueness
        $originalUsername = $username;
        $suffix = 1;
        while (self::where('username', $username)->exists()) {
            $username = $originalUsername . $suffix;
            $suffix++;
        }

        return $username;
    }
}
