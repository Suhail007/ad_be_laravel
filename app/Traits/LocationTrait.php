<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait LocationTrait
{
    /**
     * Get user's state from IP address
     * 
     * @param string $ip IP address
     * @return array|null ['state' => 'IL', 'location' => 'US-IL'] or null if not in US
     */
    protected function getUserLocationFromIP($ip = null)
    {
        if (!$ip) {
            $ip = request()->ip();
        }

        return Cache::remember("geo_state_{$ip}", now()->addHours(6), function () use ($ip) {
            try {
                $response = Http::get("https://ipapi.co/{$ip}/json/");
                if ($response->successful()) {
                    $data = $response->json();
                    // Only proceed if it's a US location
                    if ($data['country_code'] === 'US') {
                        $state = $data['region_code'] ?? null;
                        return $state ? [
                            'state' => $state,
                            'location' => "US-{$state}"
                        ] : null;
                    }
                }
                return null;
            } catch (\Exception $e) {
                Log::error('IP Geolocation error: ' . $e->getMessage());
                return null;
            }
        });
    }
} 