<?php

namespace App\Http\Controllers;

use App\Models\DiscountRule;
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
                $discountRules = DiscountRule::where('id',220)->where('enabled',1)->get();
                return response()->json($discountRules);
            }else {
                return response()->json(['status'=>'failure','message'=>'You don\'t have any discount'],401);
            }
        } catch (\Throwable $th) {
            return response()->json(['status'=>'error','message'=>$th->getMessage()],401);
        }
    }
}
