<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckoutController extends Controller
{

    public function freezeCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $cartItems = Cart::where('user_id', $user->ID)->get();
        $userIp = $request->ip();
        if ($cartItems->isEmpty()) {
            return response()->json([
                'status' => false,
                'username' => $user->user_login,
                'message' => 'Cart is empty',
                'data' => $userIp,
                'time' => now()->toDateTimeString(),
                'cart_count' => 0,
                'cart_items' => [],
            ], 200);
        }

        $priceTier = $user->price_tier;
        $cartData = [];
        $adjustedItems = [];

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;
            $wholesalePrice = 0;

            if ($variation) {
                $wholesalePrice = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
            } else {
                $wholesalePrice = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
            }

            $stockLevel = 0;
            $stockStatus = 'outofstock';
            if ($variation) {
                $stockLevel = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            } else {
                $stockLevel = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            }

            if ($stockStatus === 'instock' && $stockLevel > 0) {
                $stockStatus = 'instock';
            } else {
                $stockStatus = 'outofstock';
            }

            $adjusted = false;
            $originalQuantity = $cartItem->quantity;
            if ($cartItem->quantity > $stockLevel) {
                $cartItem->quantity = $stockLevel;
                $cartItem->save();
                $adjusted = true;
            }

            $variationAttributes = [];
            if ($variation) {
                $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                foreach ($attributes as $attribute) {
                    $variationAttributes[] = $attribute->meta_value;
                }
            }

            $productSlug = $product->post_name;

            $cartData[] = [
                'key' => $cartItem->id,
                'product_id' => $product->ID,
                'product_name' => $product->post_title,
                'product_slug' => $productSlug,
                'product_price' => $wholesalePrice,
                'product_image' => $product->thumbnail_url,
                'stock' => $stockLevel,
                'stock_status' => $stockStatus,
                'quantity' => $cartItem->quantity,
                'variation_id' => $variation ? $variation->ID : null,
                'variation' => $variationAttributes,
            ];

            if ($adjusted) {
                $adjustedItems[] = [
                    'product_id' => $product->ID,
                    'product_name' => $product->post_title,
                    'requested_quantity' => $originalQuantity,
                    'available_quantity' => $stockLevel,
                ];
            }
        }

        $response = [
            'status' => true,
            'username' => $user->user_login,
            'message' => 'Cart items frozen',
            'data' => $userIp,
            'time' => now()->toDateTimeString(),
            'cart_count' => count($cartData),
            'cart_items' => $cartData,
        ];

        if (!empty($adjustedItems)) {
            $response['adjusted_items'] = $adjustedItems;
            $response['message'] = 'Some items were adjusted due to stock availability';
        }

        return response()->json($response, 200);
    }
}
