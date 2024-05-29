<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $table = 'wp_users'; // Specify the WordPress users table

    protected $primaryKey = 'ID'; // Set the primary key to 'ID'

    protected $fillable = [
        'user_email', 'user_pass', // Add other fields as necessary
    ];

    protected $hidden = [
        'user_pass', // Hide the password field
    ];

    public function getAuthPassword()
    {
        return $this->user_pass; // Return the password for authentication
    }

    public function getJWTIdentifier()
    {
        return $this->getKey(); // Ensure this returns the 'ID' field value
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
