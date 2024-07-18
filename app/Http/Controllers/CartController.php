<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkAddToCartRequest;
use App\Http\Requests\UpdateCartQuantityRequest;
use Illuminate\Http\Request;
use App\Models\Cart;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function addToCart(Request $request)
    {
        $cart = Cart::create([
            'user_id' => $request->user_id,
            'product_id' => $request->product_id,
            'variation_id' => $request->variation_id,
            'quantity' => $request->quantity,
        ]);

        return response()->json(['success' => 'Product added to cart', 'cart' => $cart], 200);
    }
    public function bulkAddToCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $user_id = $user->ID;
        $product_id = $request->product_id;
        $variations = $request->variations;

        $cartItems = [];

        foreach ($variations as $variation) {
            $cartItem = Cart::where('user_id', $user_id)
                ->where('product_id', $product_id)
                ->where('variation_id', $variation['variation_id'])
                ->first();

            if ($cartItem) {
                $cartItem->quantity += $variation['quantity'];
                $cartItem->save();
            } else {
                $cartItem = Cart::create([
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'variation_id' => $variation['variation_id'],
                    'quantity' => $variation['quantity'],
                ]);
            }

            $cartItems[] = $cartItem;
        }

        $cartItems = Cart::where('user_id', $user->ID)->get();
        $userIp = $request->ip();
        if ($cartItems->isEmpty()) {
            return response()->json([
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

            $variationAttributes = [];
            if ($variation) {
                $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                foreach ($attributes as $attribute) {
                    $variationAttributes[] = $attribute->meta_value;
                }
            }

            $productSlug = $product->post_name;

            $categoryIds = $product->categories->pluck('term_id')->toArray();

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
                'taxonomies' => $categoryIds
            ];
        }

        return response()->json([
            'status' => true,
            'success' => 'Products added to cart',
            'data' => $userIp,
            // 'time' => now()->toDateTimeString(),
            // 'cart_count' => count($cartData),
            'cart' => $cartData,
        ], 200);
        // return response()->json(['success' => 'Products added to cart', 'cart' => $cartItems], 200);
    }

    public function getCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => 'error'], 401);
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

            $variationAttributes = [];
            if ($variation) {
                $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                foreach ($attributes as $attribute) {
                    $variationAttributes[] = $attribute->meta_value;
                }
            }

            $productSlug = $product->post_name;

            $categoryIds = $product->categories->pluck('term_id')->toArray();

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
                'taxonomies' => $categoryIds
            ];
        }

        return response()->json([
            'status' => true,
            'username' => $user->user_login,
            'message' => 'Cart items',
            'data' => $userIp,
            'time' => now()->toDateTimeString(),
            'cart_count' => count($cartData),
            'cart_items' => $cartData,
        ], 200);
    }


    public function deleteFromCart($id)
    {
        $cart = Cart::findOrFail($id);
        $cart->delete();

        return response()->json(['status' => true, 'success' => 'Product removed from cart'], 200);
    }

    public function updateCartQuantity(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not authenticated'], 200);
        }
        $cartItem = Cart::where('user_id', $user->ID)
            ->where('product_id', $request->product_id)
            ->where('variation_id', $request->variation_id)
            ->first();
        if (!$cartItem || $request->quantity <= 0) {
            return response()->json(['status' => false, 'message' => 'Item not found'], 200);
        }
        $cartItem->quantity = $request->quantity;
        $cartItem->save();
        return response()->json(['message' => 'Item quantity updated',  'status' => true], 200);
    }
    public function empty(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }
        $user_id = $user->ID;
        Cart::where('user_id', $user_id)->delete();
        return response()->json(['message' => 'All items removed', 'status' => true], 200);
    }
}
