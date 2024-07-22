<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Checkout;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Jobs\UnfreezeCart;
use Carbon\Carbon;


class CheckoutController extends Controller
{
    public function checkoutAddress(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => 'Unknown User'], 401);
        }

        $validate = Validator::make($request->all(), [
            'billing' => 'required|array',
            'shipping' => 'required|array'
        ]);

        if ($validate->fails()) {
            return response()->json(['status' => false, 'message' => $validate->errors()], 401);
        }

        $data = $request->all();
        $response = $this->freezeCart($request);
        $checkout = Checkout::updateOrCreate(
            ['user_id' => $user->ID],
            [
                'is_freeze' => true,
                'billing' => $data['billing'],
                'shipping' => $data['shipping']
            ]
        );
        UnfreezeCart::dispatch($user->ID)->delay(now()->addMinutes(10));
        return response()->json(['status' => true, 'message' => 'Address Selected Successfully', 'data' => $response], 201);
    }
    public function freezeCart(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Throwable $th) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $cartItems = Cart::where('user_id', $user->ID)->get();
        if ($cartItems->isEmpty()) {
            return response()->json([
                'status' => false,
                'username' => $user->user_login,
                'message' => 'Cart is empty',
                'data' => $request->ip(),
                'time' => now()->toDateTimeString(),
                'cart_count' => 0,
                'cart_items' => [],
            ], 200);
        }

        $cartData = [];
        $adjustedItems = [];

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;

            if ($product->post_status !== 'publish') {
                $cartItem->delete();
                $adjustedItems[] = [
                    'product_id' => $product->ID,
                    'product_name' => $product->post_title,
                    'product_image' => $product->thumbnail_url,
                    'message' => 'Product is not published and has been removed from the cart',
                ];
                continue;
            }

            $wholesalePrice = $this->getWholesalePrice($variation, $product, $user->price_tier);
            list($stockLevel, $stockStatus) = $this->getStockInfo($variation, $product);

            $adjusted = false;
            $originalQuantity = $cartItem->quantity;
            if ($cartItem->quantity > $stockLevel) {
                $cartItem->quantity = $stockLevel;
                $cartItem->save();
                $adjusted = true;
            }

            $variationAttributes = $this->getVariationAttributes($variation);

            $cartData[] = [
                'key' => $cartItem->id,
                'product_id' => $product->ID,
                'product_name' => $product->post_title,
                'product_slug' => $product->post_name,
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
                    'product_image' => $product->thumbnail_url,
                    'requested_quantity' => $originalQuantity,
                    'available_quantity' => $stockLevel,
                    'message' => 'Quantity adjusted due to stock availability',
                ];
            }
        }

        $response = [
            'status' => true,
            'username' => $user->user_login,
            'message' => 'Cart items frozen',
            'data' => $request->ip(),
            'time' => now()->toDateTimeString(),
            'cart_count' => count($cartData),
            'cart_items' => $cartData,
        ];

        if (!empty($adjustedItems)) {
            $response['adjusted_items'] = $adjustedItems;
            $response['message'] = 'Some items were adjusted due to stock availability or publication status';
        }

        return response()->json($response);
    }

    private function getWholesalePrice($variation, $product, $priceTier)
    {
        if ($variation) {
            return ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', $priceTier)
                ->value('meta_value');
        } else {
            return ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', $priceTier)
                ->value('meta_value');
        }
    }

    private function getStockInfo($variation, $product)
    {
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

        $stockStatus = ($stockStatus === 'instock' && $stockLevel > 0) ? 'instock' : 'outofstock';

        return [$stockLevel, $stockStatus];
    }

    private function getVariationAttributes($variation)
    {
        $variationAttributes = [];
        if ($variation) {
            $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
            foreach ($attributes as $attribute) {
                $variationAttributes[] = $attribute->meta_value;
            }
        }
        return $variationAttributes;
    }
}
