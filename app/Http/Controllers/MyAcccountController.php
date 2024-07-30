<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class MyAcccountController extends Controller
{
    public function getUserAddresses(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                    'status' => false,
                ], 200);
            }

            $customAddressesMeta = UserMeta::where('user_id', $user->ID)
                ->where('meta_key', 'thwma_custom_address')
                ->value('meta_value');

            $customAddresses = unserialize($customAddressesMeta);

            $customAddressesUnapprove = UserMeta::where('user_id', $user->ID)
            ->where('meta_key', 'custom_requested_addresses')
            ->value('meta_value');

            $customAddressesUnapprove = unserialize($customAddressesUnapprove);
            
            $defaultAddress = [
                'billing' => [
                    'first_name' => $this->getUserMeta($user->ID, 'billing_first_name'),
                    'last_name' => $this->getUserMeta($user->ID, 'billing_last_name'),
                    'company' => $this->getUserMeta($user->ID, 'billing_company'),
                    'address_1' => $this->getUserMeta($user->ID, 'billing_address_1'),
                    'address_2' => $this->getUserMeta($user->ID, 'billing_address_2'),
                    'city' => $this->getUserMeta($user->ID, 'billing_city'),
                    'state' => $this->getUserMeta($user->ID, 'billing_state'),
                    'postcode' => $this->getUserMeta($user->ID, 'billing_postcode'),
                    'country' => $this->getUserMeta($user->ID, 'billing_country'),
                    'email' => $this->getUserMeta($user->ID, 'billing_email'),
                    'phone' => $this->getUserMeta($user->ID, 'billing_phone'),
                ],
                'shipping' => [
                    'first_name' => $this->getUserMeta($user->ID, 'shipping_first_name'),
                    'last_name' => $this->getUserMeta($user->ID, 'shipping_last_name'),
                    'company' => $this->getUserMeta($user->ID, 'shipping_company'),
                    'address_1' => $this->getUserMeta($user->ID, 'shipping_address_1'),
                    'address_2' => $this->getUserMeta($user->ID, 'shipping_address_2'),
                    'city' => $this->getUserMeta($user->ID, 'shipping_city'),
                    'state' => $this->getUserMeta($user->ID, 'shipping_state'),
                    'postcode' => $this->getUserMeta($user->ID, 'shipping_postcode'),
                    'country' => $this->getUserMeta($user->ID, 'shipping_country'),
                ],
            ];
            if (empty($customAddresses)) {
                $customAddresses=[];
            }
            if(empty($customAddressesUnapprove)){
                $customAddressesUnapprove=[];
            }
            return response()->json([
                'status' => true,
                'username' => $user->user_login,
                'message' => 'User addresses',
                'unapproved'=>$customAddressesUnapprove,
                'addresses' => $customAddresses,
                'defaultAddress' => $defaultAddress,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'status' => false,
            ], 200);
        }
    }

    private function getUserMeta($userId, $key)
    {
        return UserMeta::where('user_id', $userId)
            ->where('meta_key', $key)
            ->value('meta_value');
    }

    // private function maybe_unserialize($value)
    // {
    //     $unserialized = @unserialize($value);
    //     return ($unserialized !== false || $value === 'b:0;') ? $unserialized : $value;
    // }
}
