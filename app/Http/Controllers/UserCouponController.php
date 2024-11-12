<?php

namespace App\Http\Controllers;

use App\Models\UserCoupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserCouponController extends Controller
{
    // List all coupons
    public function index()
    {
        $coupons = UserCoupon::all();
        return response()->json(['status'=>true,'data'=>$coupons]);
    }

    // Show a single coupon
    public function show($id)
    {
        $coupon = UserCoupon::find($id);
        
        if (!$coupon) {
            return response()->json(['status'=>false,'message' => 'Offer not found']);
        }

        return response()->json(['status'=>true,'data'=>$coupon]);
    }

    // Store a new coupon
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'couponName' => 'required|string|max:255',
            'qrDetail' => 'nullable|string',
            'discountRuleId' => 'nullable',
            'email' => 'nullable|email',
            'canUse' => 'nullable|boolean',
            'meta' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>true,'message' => $validator->errors()]);
        }

        $coupon = UserCoupon::create($request->all());

        return response()->json(['status'=>true,'message'=>'Offer created for you','data'=>$coupon]);
    }

    // Update an existing coupon
    public function update(Request $request, $id)
    {
        $coupon = UserCoupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'couponName' => 'required|string|max:255',
            'qrDetail' => 'nullable|string',
            'discountRuleId' => 'nullable',
            'email' => 'nullable|email',
            'canUse' => 'nullable|boolean',
            'meta' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>false,'message' => $validator->errors()]);
        }

        $coupon->update($request->all());

        return response()->json($coupon);
    }

    // Delete a coupon
    public function destroy($id)
    {
        $coupon = UserCoupon::find($id);

        if (!$coupon) {
            return response()->json(['status'=>true,'message' => 'Coupon not found']);
        }

        $coupon->delete();

        return response()->json(['status'=>true,'message' => 'Coupon deleted successfully']);
    }
}
