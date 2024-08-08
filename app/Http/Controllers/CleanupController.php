<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CleanupController extends Controller
{
    public function menuCleanUp()
    {

        $brandUrls = DB::table('wp_custom_value_save')->pluck('brand_url');

        // Step 2: Process each brand URL
        foreach ($brandUrls as $brandUrl) {

            $slug = trim(parse_url($brandUrl, PHP_URL_PATH), 'brand/');

            // Check if there are products associated with this slug
            $hasProducts = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                }
                ])
                ->select('ID', 'post_title', 'post_modified', 'post_name')
                ->where('post_type', 'product')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                    $query->where('slug', $slug)
                        ->where('taxonomy', 'product_brand');
                })
                ->exists(); // Check if any products exist

            if (!$hasProducts) {
                DB::table('wp_custom_value_save')
                    ->where('brand_url', $brandUrl)
                    ->delete();
            }
        }
        return true;
    }


    public function cartSync(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 200);
        }
        
        if ($user->ID != 1) {
            return response()->json(['message' => 'User are not allowed', 'status' => false], 200);
        }
        
      
        $chunkSize = 100; 
    
        DB::table('wp_users')->orderBy('ID')->chunk($chunkSize, function ($users) {
            foreach ($users as $user) {
                $carts = DB::table('wp_usermeta')
                    ->where('user_id', $user->ID)
                    ->where('meta_key', 'LIKE', '%_woocommerce_persistent_cart_%')
                    ->get();
    
                foreach ($carts as $cart) {
                    $cart_data = unserialize($cart->meta_value);
    
                    if ($cart_data && isset($cart_data['cart'])) {
                        foreach ($cart_data['cart'] as $cart_item_key => $item) {
                            $product_id = $item['product_id'] ?? null;
                            $variation_id = $item['variation_id'] ?? null;
                            $quantity = $item['quantity'] ?? 0;
    
                            if ($product_id && !DB::table('wp_posts')->where('ID', $product_id)->exists()) {
                                echo "Product ID $product_id does not exist. Skipping...<br>";
                                continue;
                            }
    
                            if ($variation_id !== null && $variation_id !== '' && !DB::table('wp_posts')->where('ID', $variation_id)->exists()) {
                                echo "Variation ID $variation_id does not exist. Skipping...<br>";
                                continue;
                            }
    
                           
                            if ($variation_id === 0) {
                                echo "Variation ID is 0. Skipping...<br>";
                                continue;
                            }
    
                          
                            $cartItem = Cart::where('user_id', $user->ID)
                                ->where('product_id', $product_id)
                                ->where('variation_id', $variation_id)
                                ->first();
    
                            if ($cartItem) {
                              
                                $cartItem->quantity += $quantity;
                                $cartItem->save();
                            } else {
                               
                                Cart::create([
                                    'user_id' => $user->ID,
                                    'product_id' => $product_id,
                                    'variation_id' => $variation_id,
                                    'quantity' => $quantity,
                                ]);
                            }
                        }
                    }
                }
    
                echo $user->ID . ' user cart synced <br>';
            }
        });
    }
    
    


}
