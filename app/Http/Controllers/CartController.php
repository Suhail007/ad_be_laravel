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
    public function bulkAddToCart(BulkAddToCartRequest $request)
    {
        $user_id = $request->user_id;
        $product_id = $request->product_id;
        $variations = $request->variations;

        $cartItems = [];

        foreach ($variations as $variation) {
            $cartItems[] = Cart::create([
                'user_id' => $user_id,
                'product_id' => $product_id,
                'variation_id' => $variation['variation_id'],
                'quantity' => $variation['quantity'],
            ]);
        }

        return response()->json(['success' => 'Products added to cart', 'cart' => $cartItems], 200);
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
            $attributes = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_product_attributes')
                ->value('meta_value');
            $attributes = maybe_unserialize($attributes); // Unserialize the attributes

            foreach ($attributes as $attribute_name => $attribute_data) {
                $attribute_value = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', 'attribute_' . $attribute_name)
                    ->value('meta_value');
                $variationAttributes[$attribute_name] = $attribute_value;
            }
        }

        $cartData[] = [
            'key' => $cartItem->id,
            'product_id' => $product->ID,
            'product_name' => $product->post_title,
            'product_price' => $wholesalePrice,
            'product_image' => $product->thumbnail_url,
            'stock' => $stockLevel,
            'stock_status' => $stockStatus,
            'quantity' => $cartItem->quantity,
            'variation_id' => $variation ? $variation->ID : null,
            'variation' => $variationAttributes,
        ];
    }

    return response()->json([
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

        return response()->json(['success' => 'Product removed from cart'], 200);
    }
    
    public function updateCartQuantity(UpdateCartQuantityRequest $request){
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => 'error'], 401);
        }
        $cartItem = Cart::where('user_id', $user->ID)
            ->where('product_id', $request->product_id)
            ->where('variation_id', $request->variation_id)
            ->first();
        if (!$cartItem) {
            return response()->json(['message' => 'Item not found', 'status' => 'error'], 404);
        }
        $cartItem->quantity = $request->quantity;
        $cartItem->save();
        return response()->json(['message' => 'Item quantity updated', 'status' => 'success'], 200);
    }
}
