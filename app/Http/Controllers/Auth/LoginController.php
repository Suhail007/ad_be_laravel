<?php

// app/Http/Controllers/Auth/LoginController.php

namespace App\Http\Controllers\Auth;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserMeta;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use MikeMcLin\WpPassword\Facades\WpPassword;
class LoginController extends Controller
{

    
    public function login(Request $request)
    {
        $email = $request->input('user_email');
        $hashedPassword = $request->input('password');
        $user = User::where('user_email', $email)->orWhere('user_login',$email)->first();

        $check = WpPassword::check($hashedPassword, $user->user_pass);
        if ( $check==true ) {
            $data = [
                'ID' => $user->ID,
                'name' => $user->user_login,
                'email' => $user->user_email,
                'capabilities' => $user->capabilities, 
                'account_no'=>$user->account
            ];
            if ($token = JWTAuth::fromUser($user)) {
                return response()->json([
                    'status' => 'success',
                    'token' => $token,
                    'data'=>$data,
                ]);
            }
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }
    }

    public function logout(Request $request)
    {
        try {
            $token = $request->header('Authorization');
            $token = str_replace('Bearer ', '', $token);
            JWTAuth::invalidate($token);
            // JWTAuth::invalidate($request->token);
            return response()->json(['status' => 'success', 'message' => 'User logged out successfully']);
        } catch (JWTException $exception) {
            return response()->json(['status' => 'error', 'message' => 'Could not log out the user'], 500);
        }
    }

    public function register(Request $request)
    {
        $request->validate([
            'user_login' => 'required|string|unique:wp_users,user_login',
            'user_email' => 'required|string|email|unique:wp_users,user_email',
            'password' => 'required|string|confirmed|min=8',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'nickname' => 'required|string',
            'billing_company' => 'nullable|string',
            'billing_address_1' => 'nullable|string',
            'billing_city' => 'nullable|string',
            'billing_state' => 'nullable|string',
            'billing_postcode' => 'nullable|string',
            'shipping_company' => 'nullable|string',
            'shipping_address_1' => 'nullable|string',
            'shipping_city' => 'nullable|string',
            'shipping_state' => 'nullable|string',
            'shipping_postcode' => 'nullable|string',
        ]);

        $user = User::create([
            'user_login' => $request->input('user_login'),
            'user_pass' => WpPassword::make($request->input('password')), 
            'user_nicename' => $request->input('user_login'),
            'user_email' => $request->input('user_email'),
            'user_registered' => Carbon::now(),
            'display_name' => $request->input('user_login'),
        ]);

        $userMetaFields = [
            'nickname' => $request->input('nickname'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'billing_company' => $request->input('billing_company'),
            'billing_address_1' => $request->input('billing_address_1'),
            'billing_city' => $request->input('billing_city'),
            'billing_state' => $request->input('billing_state'),
            'billing_postcode' => $request->input('billing_postcode'),
            'shipping_company' => $request->input('shipping_company'),
            'shipping_address_1' => $request->input('shipping_address_1'),
            'shipping_city' => $request->input('shipping_city'),
            'shipping_state' => $request->input('shipping_state'),
            'shipping_postcode' => $request->input('shipping_postcode'),
        ];

        foreach ($userMetaFields as $key => $value) {
            if (!empty($value)) {
                UserMeta::create([
                    'user_id' => $user->ID,
                    'meta_key' => $key,
                    'meta_value' => $value,
                ]);
            }
        }

        UserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => 'wp_capabilities',
            'meta_value' => serialize(['customer' => true]),  
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'user' => [
                'ID' => $user->ID,
                'name' => $user->user_login,
                'email' => $user->user_email,
                'capabilities' => $user->capabilities,
                'account_no' => $user->account,
            ],
        ]);
    }
    public function me(Request $request){
        $user = JWTAuth::parseToken()->authenticate();
        $data =[
            'ID'=>$user->ID,
            'name' => $user->user_login,
            'email' => $user->user_email,
            'capabilities' => $user->capabilities,
            'account_no' => $user->account, 
        ];
        return response()->json(['status'=>'success','data'=>$data]);
    }

}

