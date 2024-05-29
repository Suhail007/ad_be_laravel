<?php

// app/Http/Controllers/Auth/LoginController.php

namespace App\Http\Controllers\Auth;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use MikeMcLin\WpPassword\Facades\WpPassword;
class LoginController extends Controller
{
    public function login(Request $request)
    {
        $email = $request->input('user_email');
        $hashedPassword = $request->input('password');
        $user = User::where('user_email', $email)->first();
        $check = WpPassword::check($hashedPassword, $user->user_pass);
        if ( $check==true ) {
            // $data= [];
            // $data['name']= $user->user_login;
            // $data['email']=$user->user_email;
            // $data['password']=$user->user_pass;
            // //return print_r($data);
           
            if ($token = JWTAuth::fromUser($user)) {
                return response()->json([
                    'status' => 'success',
                    'token' => $token,
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
            JWTAuth::invalidate($request->token);
            return response()->json(['status' => 'success', 'message' => 'User logged out successfully']);
        } catch (JWTException $exception) {
            return response()->json(['status' => 'error', 'message' => 'Could not log out the user'], 500);
        }
    }

}

