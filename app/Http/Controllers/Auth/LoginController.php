<?php

// app/Http/Controllers/Auth/LoginController.php

namespace App\Http\Controllers\Auth;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use MikeMcLin\WpPassword\Facades\WpPassword;
class LoginController extends Controller
{

    public function fetchApiData()
    {
        $username = 'jass.suhail';
        $authKey = '6FlFHt6Nr4vYBFtUjFq6jxc1HXzPKXEyDcLV7SBn7WZ8%2FaARsVHfzAgoxtnV7Rav';
        $authSecret = 'pulKQmYV2lhY5rsW0sGogrwG%2B17Kv4U7Kk8B9AA5kJ3w8XG6ifa0kQtWOOCRSkzS';

        $url = 'https://ad.phantasm.solutions/wp-json/ade-woocart/v1/check';

        $response = Http::get($url, [
            'username' => $username,
            'authKey' => $authKey,
            'authSecret' => $authSecret,
        ]);

        if ($response->successful()) {
            return response()->json($response->json());
        } else {
            return response()->json(['error' => 'Failed to fetch data'], $response->status());
        }
    }
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
                'capabilities' => $user->capabilities, // Fetch the capabilities attribute
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

    public function me(Request $request){
        $user = JWTAuth::parseToken()->authenticate();
        $data =[
            'ID'=>$user->ID,
            'name' => $user->user_login,
            'email' => $user->user_email,
            'capabilities' => $user->capabilities, 
        ];
        return response()->json(['status'=>'success','data'=>$data]);
    }

}

