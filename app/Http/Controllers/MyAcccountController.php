<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class MyAcccountController extends Controller
{

    public function updateAddress(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'status' => false,
            ], 200);
        }

        $validated = $request->validate([
            'type' => 'required|string',
            'address_key' => 'required|string',
            'first_name' => 'required|string',
            'last_name' => 'string',
            'company' => 'nullable|string',
            'country' => 'required|string',
            'state' => 'required|string',
            'address_1' => 'required|string',
            'address_2' => 'nullable|string',
            'city' => 'required|string',
            'postcode' => 'required|string',
            'phone' => 'required|string',
            'email' => 'required|email',
            'file' => 'required'
        ]);

        $userId = $user->ID;
        $addressKey = $validated['address_key'];
        $type = $validated['type'];
        $prefix = $type === 'billing' ? 'billing_' : 'shipping_';

        // Fetch the existing custom addresses
        $userMeta = UserMeta::where('user_id', $userId)
            ->where('meta_key', 'thwma_custom_address')
            ->value('meta_value');

        // Unserialize the existing data
        $addresses = unserialize($userMeta) ?: [];

        // Prepare the new address data
        $newAddress = [
            $prefix . 'first_name' => $validated['first_name'],
            $prefix . 'last_name' => $validated['last_name'],
            $prefix . 'company' => $validated['company'] ?? '',
            $prefix . 'country' => $validated['country'],
            $prefix . 'state' => $validated['state'],
            $prefix . 'address_1' => $validated['address_1'],
            $prefix . 'address_2' => $validated['address_2'] ?? '',
            $prefix . 'city' => $validated['city'],
            $prefix . 'postcode' => $validated['postcode'],
            $prefix . 'phone' => $validated['phone'],
            $prefix . 'email' => $validated['email'],
            'licence' => $validated['file']
        ];

        // Check if the address exists in the approved addresses
        if (isset($addresses[$type][$addressKey])) {
            // Remove from approved addresses
            $removedAddress = $addresses[$type][$addressKey];
            unset($addresses[$type][$addressKey]);

            // Add to unapproved addresses
            $requestedAddresses = UserMeta::where('user_id', $userId)
                ->where('meta_key', 'custom_requested_addresses')
                ->value('meta_value');

            $requestedAddresses = unserialize($requestedAddresses) ?: [];
            if (!isset($requestedAddresses[$type])) {
                $requestedAddresses[$type] = [];
            }
            $requestedAddresses[$type][$addressKey] = $newAddress;

            // Save updated addresses to custom_requested_addresses
            $serializedRequestedAddresses = serialize($requestedAddresses);
            UserMeta::updateOrCreate(
                ['user_id' => $userId, 'meta_key' => 'custom_requested_addresses'],
                ['meta_value' => $serializedRequestedAddresses]
            );

            // Update approved addresses
            $serializedAddresses = serialize($addresses);
            UserMeta::updateOrCreate(
                ['user_id' => $userId, 'meta_key' => 'thwma_custom_address'],
                ['meta_value' => $serializedAddresses]
            );

            return response()->json(['message' => 'Address updated successfully. Wait For Admin Approval!']);
        } else {
            return response()->json(['message' => 'Address not found.'], 404);
        }
    }


    // public function defaultAddresses(Request $request)
    // {
    //     $user = JWTAuth::parseToken()->authenticate();
    //     if (!$user) {
    //         return response()->json([
    //             'message' => 'User not found',
    //             'status' => false,
    //         ], 200);
    //     }
    //     $prefix =  'shipping_';
    //     $shipping = [
    //         'first_name' => $this->getUserMeta($user->ID, 'shipping_first_name'),
    //         'last_name' => $this->getUserMeta($user->ID, 'shipping_last_name'),
    //         'company' => $this->getUserMeta($user->ID, 'shipping_company'),
    //         'address_1' => $this->getUserMeta($user->ID, 'shipping_address_1'),
    //         'address_2' => $this->getUserMeta($user->ID, 'shipping_address_2'),
    //         'city' => $this->getUserMeta($user->ID, 'shipping_city'),
    //         'state' => $this->getUserMeta($user->ID, 'shipping_state'),
    //         'postcode' => $this->getUserMeta($user->ID, 'shipping_postcode'),
    //         'country' => $this->getUserMeta($user->ID, 'shipping_country'),
    //     ];
    // }

    public function updateOrCreateAddresses(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'status' => false,
            ], 200);
        }

        $userId =$user->ID; // $request->input('user_id');
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
            'licence' => $request->input('file'),
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
