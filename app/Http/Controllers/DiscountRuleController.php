<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class DiscountRuleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if($user){
                $discountRules = DB::table('discount_rules')->get();
                return response()->json($discountRules);
            }else {
                return response()->json(['status'=>'failure','message'=>'You don\'t have any discount'],401);
            }
        } catch (\Throwable $th) {
            return response()->json(['status'=>'error','message'=>$th->getMessage()],401);
        }
    }
}
