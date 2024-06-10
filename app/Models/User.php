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
    public function meta()
    {
        return $this->hasMany(UserMeta::class, 'user_id', 'ID');
    }
    public function getPriceTierAttribute()
    {
        $capabilities = $this->meta()->where('meta_key', 'wp_capabilities')->value('meta_value');

        if ($capabilities) {
            $capabilitiesArray = unserialize($capabilities);
            if (isset($capabilitiesArray['wholesale_customer'])) {
                return 'wholesale_customer_wholesale_price';
            } elseif (isset($capabilitiesArray['mm_price_2'])) {
                return 'mm_price_2_wholesale_price';
            } elseif (isset($capabilitiesArray['mm_price_3'])) {
                return 'mm_price_3_wholesale_price';
            } elseif (isset($capabilitiesArray['mm_price_4'])) {
                return 'mm_price_4_wholesale_price';
            }
        }
        return null;
    }
    public function getCapabilitiesAttribute()
    {
        $capabilities = $this->meta()->where('meta_key', 'wp_capabilities')->value('meta_value');
        return $capabilities ? unserialize($capabilities) : [];
    }
}
