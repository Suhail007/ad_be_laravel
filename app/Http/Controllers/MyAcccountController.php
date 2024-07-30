<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class MyAcccountController extends Controller
{
    public function updateOrCreateAddresses(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'status' => false,
            ], 200);
        }

        $userId = $request->input('user_id');
        $type = $request->input('type');
        $prefix = $type === 'billing' ? 'billing_' : 'shipping_';
        $newAddress = [
            $prefix . 'first_name' => $request->input('first_name'),
            $prefix . 'last_name' => $request->input('last_name'),
            $prefix . 'company' => $request->input('company'),
            $prefix . 'country' => $request->input('country'),
            $prefix . 'state' => $request->input('state'),
            $prefix . 'address_1' => $request->input('address_1'),
            $prefix . 'address_2' => $request->input('address_2'),
            $prefix . 'city' => $request->input('city'),
            $prefix . 'postcode' => $request->input('postcode'),
            $prefix . 'phone' => $request->input('phone'),
            $prefix . 'email' => $request->input('email'),
            'licence' => $request->input('fileurl'),
        ];

        $addresses = UserMeta::where('user_id', $userId)
            ->where('meta_key', 'custom_requested_addresses')
            ->pluck('meta_value', 'meta_key')
            ->first();

        $addresses = $addresses ? unserialize($addresses) : [];
        if (!isset($addresses[$type])) {
            $addresses[$type] = [];
        }
        $nextIndex = count($addresses[$type]);
        $addresses[$type]['address_' . $nextIndex] = $newAddress;

        UserMeta::updateOrCreate(
            ['user_id' => $userId, 'meta_key' => 'custom_requested_addresses'],
            ['meta_value' => serialize($addresses)]
        );

        return response()->json(['message' => 'Address and licence submitted successfully']);
    }

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
                $customAddresses = [];
            }
            if (empty($customAddressesUnapprove)) {
                $customAddressesUnapprove = [];
            }
            return response()->json([
                'status' => true,
                'username' => $user->user_login,
                'message' => 'User addresses',
                'unapproved' => $customAddressesUnapprove,
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
