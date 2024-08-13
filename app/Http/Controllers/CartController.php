<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkAddToCartRequest;
use App\Http\Requests\UpdateCartQuantityRequest;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Checkout;
use App\Models\MMTax;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    private function cartTotal($cartItems, $priceTier)
    {
        $total = 0;
        $taxID = [];
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;
            $wholesalePrice = 0;

            if ($variation) {
                $wholesalePrice = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $taxID = ProductMeta::where('post_id', $variation->ID)->where('meta_key', 'mm_indirect_tax_type')->value('meta_value');
            } else {
                $wholesalePrice = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $taxID = ProductMeta::where('post_id', $product->ID)->where('meta_key', 'mm_indirect_tax_type')->value('meta_value');
            }

            $total += round($wholesalePrice * $cartItem->quantity, 2);
        }

        return [round($total, 2), $taxID];
    }


    protected function reduceStock($cartItem)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $cartItem->quantity);
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $cartItem->quantity);
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }

    protected function adjustStock($cartItem, $oldQuantity, $newQuantity)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;
        $quantityChange = $newQuantity - $oldQuantity;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantityChange);
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantityChange);
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }

    protected function increaseStock($cartItem)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = $stockLevel + $cartItem->quantity;
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = $stockLevel + $cartItem->quantity;
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }
    protected function reduceStockByQuantity($cartItem, $quantity)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantity);
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantity);
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }

    public function tax(Request $request)
    {
        $tax = MMTax::get();
        return response()->json($tax);
    }

    private function cartItemCount($cartItems)
    {
        $totalCount = 0;
        foreach ($cartItems as $cartItem) {
            $totalCount += $cartItem->quantity;
        }
        return $totalCount;
    }


    public function bulkAddToCart(Request $request)
    {

        $user = JWTAuth::parseToken()->authenticate();
        $user_id = $user->ID;

        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        $product_id = $request->product_id;
        $variations = $request->variations;

        $cartItems = [];


        foreach ($variations as $variation) {
            $cartItem = Cart::where('user_id', $user_id)
                ->where('product_id', $product_id)
                ->where('variation_id', $variation['variation_id'])
                ->first();

            if ($cartItem) {

                $newQty = $variation['quantity'];
                $cartItem->quantity += $variation['quantity'];
                $cartItem->save();
                if ($isFreeze) {
                    $this->reduceStockByQuantity($cartItem, $newQty);
                }
            } else {
                $cartItem = Cart::create([
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'variation_id' => $variation['variation_id'],
                    'quantity' => $variation['quantity'],
                ]);
                if ($isFreeze) {
                    $this->reduceStock($cartItem);
                }
            }


            $cartItems[] = $cartItem;
        }

        // $perPage = $request->input('per_page', 15); // Items per page, default to 10
        $cartItems = Cart::where('user_id', $user_id)->get();

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
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }

        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
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

            if ($stockStatus == 'instock') {
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
            'cart' => $cartData,
            'cart_total' => $total,
            // 'pagination' => [
            //     'total' => $cartItems->total(),
            //     'per_page' => $cartItems->perPage(),
            //     'current_page' => $cartItems->currentPage(),
            //     'last_page' => $cartItems->lastPage(),
            //     'next_page_url' => $cartItems->nextPageUrl(),
            //     'prev_page_url' => $cartItems->previousPageUrl(),
            // ]
        ], 200);
    }



    public function bulkUpdateCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $user_id = $user->ID;
        $items = $request->items;

        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        $cartItems = [];

        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $variation_id = $item['variation_id'];
            $quantity = $item['quantity'];

            $cartItem = Cart::where('user_id', $user_id)
                ->where('product_id', $product_id)
                ->where('variation_id', $variation_id)
                ->first();

            if ($cartItem) {
                $oldQuantity = $cartItem->quantity;
                $cartItem->quantity = $quantity;
                $cartItem->save();

                // Adjust stock levels if isFreeze is true
                if ($isFreeze) {
                    $this->adjustStock($cartItem, $oldQuantity, $quantity);
                }
            } else {
                $cartItem = Cart::create([
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'quantity' => $quantity,
                ]);

                // Reduce stock levels if isFreeze is true
                if ($isFreeze) {
                    $this->reduceStock($cartItem);
                }
            }

            $cartItems[] = $cartItem;
        }

        $perPage = $request->input('per_page', 15);
        $cartItems = Cart::where('user_id', $user_id)->get();

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
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }
        $cartData = [];
        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
        $itemCount = $this->cartItemCount($cartTotalItems);
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
            'success' => 'Cart updated successfully',
            'data' => $userIp,
            'cart' => $cartData,
            'cart_total' => $total[0],
            'location_tax' => $total[1],
            'cart_count' => $itemCount,
            // 'pagination' => [
            //     'total' => $cartItems->total(),
            //     'per_page' => $cartItems->perPage(),
            //     'current_page' => $cartItems->currentPage(),
            //     'last_page' => $cartItems->lastPage(),
            //     'next_page_url' => $cartItems->nextPageUrl(),
            //     'prev_page_url' => $cartItems->previousPageUrl(),
            // ]
        ], 200);
    }


    public function getCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => 'error'], 401);
        }

        // $perPage = $request->input('per_page', 15);
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

        $priceTier = $priceTier ?? '_regular_price';

        $cartData = [];
        $total = $this->cartTotal($cartItems, $priceTier);
        $itemCount = $this->cartItemCount($cartItems);
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;
            $wholesalePrice = 0;
            if ($variation) {
                $wholesalePrice = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $wholesalePrice = $wholesalePrice ?? ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_price')
                    ->value('meta_value');
            } else {
                $wholesalePrice = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $wholesalePrice = $wholesalePrice ?? ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_price')
                    ->value('meta_value');
            }

            $stockLevel = 0;
            $stockStatus = 'outofstock';
            $taxID = null;
            $postID = $variation ? $variation->ID : $product->ID;

            // Fetch all required meta values in a single query
            $productMeta = ProductMeta::where('post_id', $postID)
                ->whereIn('meta_key', [
                    '_stock',
                    '_stock_status',
                    'mm_indirect_tax_type',
                    '_tax_class',
                    '_sku',
                    'mm_product_basis_1',
                    'mm_product_basis_2',
                    'mm_product_basis_3',
                    'max_quantity_var',
                    'min_quantity_var'
                ])
                ->pluck('meta_value', 'meta_key');

            $stockLevel = $productMeta->get('_stock', null);
            $stockStatus = $productMeta->get('_stock_status', null);
            $taxID = $productMeta->get('mm_indirect_tax_type', null);

            $taxClass = $productMeta->get('_tax_class', null);
            if($taxClass=='parent'){
                // echo 'tax class is '.$taxClass;
                $taxClass = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_tax_class')
                ->value('meta_value');
                // echo 'tax class is '.$taxClass;
            }

            $sku = $productMeta->get('_sku', null);
            $ml1taxID = $productMeta->get('mm_product_basis_1', null);
            $ml2taxID = $productMeta->get('mm_product_basis_2', null);
            $ml3taxID = $productMeta->get('mm_product_basis_3', null);
            $max_quantity_var = $productMeta->get('max_quantity_var', null);
            $min_quantity_var = $productMeta->get('min_quantity_var', null);
            if ($stockStatus === 'instock' && $stockLevel > 0) {
                $stockStatus = 'instock';
            } else {
                $stockStatus = 'outofstock';
            }
            if ($taxID) {
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
                'taxonomies' => $categoryIds,
                'location_tax' => $taxID,
                'tax_class'=>$taxClass,
                'sku'=>$sku,
                'ml1' => $ml1taxID,
                'ml2' => $ml2taxID,
                'ml3' => $ml3taxID,
                'max_quantity_var'=>$max_quantity_var,
                'min_quantity_var'=>$min_quantity_var
            ];
        }
        $checkout = Checkout::where('user_id', $user->ID)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;
        $freeze_time= $checkout ? $checkout->updated_at: false;
        return response()->json([
            'status' => true,
            'freeze'=>$isFreeze,
            'username' => $user->user_login,
            'message' => 'Cart items',
            'data' => $userIp,
            'current_time' => now()->toDateTimeString(),
            'freeze_time' =>$freeze_time,
            'cart_total' => $total[0],
            'location_tax' => $total[1],
            'cart_count' => $itemCount,
            'cart_items' => $cartData,
        ], 200);
    }



    public function deleteFromCart($id)
    {
        $cart = Cart::findOrFail($id);
        $user = JWTAuth::parseToken()->authenticate();
        $user_id = $user->ID;

        // Check if the user has frozen their cart
        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        if ($isFreeze) {
            $this->increaseStock($cart);
        }

        $cart->delete();
        $priceTier = $user->price_tier;
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }
        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
        $itemCount = $this->cartItemCount($cartTotalItems);
        return response()->json([
            'status' => true, 'cart_total' => $total[0],
            'location_tax' => $total[1], 'cart_count' => $itemCount, 'success' => 'Product removed from cart'
        ], 200);
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

        $oldQuantity = $cartItem->quantity;
        $cartItem->quantity = $request->quantity;
        $cartItem->save();

        $checkout = Checkout::where('user_id', $user->ID)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        if ($isFreeze) {
            $this->adjustStock($cartItem, $oldQuantity, $request->quantity);
        }
        $priceTier = $user->price_tier;
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }
        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
        $itemCount = $this->cartItemCount($cartTotalItems);
        return response()->json([
            'message' => 'Item quantity updated',  'cart_total' => $total[0],
            'location_tax' => $total[1], 'cart_count' => $itemCount, 'status' => true
        ], 200);
    }

    public function empty(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $user_id = $user->ID;

        // Check if the user has frozen their cart
        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        $cartItems = Cart::where('user_id', $user_id)->get();

        if ($isFreeze) {
            foreach ($cartItems as $cartItem) {
                $this->increaseStock($cartItem);
            }
        }

        Cart::where('user_id', $user_id)->delete();
        if ($checkout) {
            $checkout->delete();
        }

        return response()->json(['message' => 'All items removed', 'cart_count' => 0, 'status' => true], 200);
    }

    public function bulkDeleteCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $user_id = $user->ID;
        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        if ($isFreeze) {
            return response()->json(['message' => 'Stock already reserved for 5 minutes, please order quickly','removed_items'=>0 ,'status' => true], 200);
        }

        $cartItems = Cart::where('user_id', $user_id)->get();
        $removedItems = [];

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;

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

            // Check if the stock level is available
            if ($stockStatus != 'instock' || $stockLevel == 0) {
                // Remove out-of-stock item from the cart
                $cartItem->delete();
                $removedItems[] = [
                    'product_id' => $product->ID,
                    'variation_id' => $variation ? $variation->ID : null,
                    'message' => 'Item removed from cart due to being out of stock',
                ];
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Out-of-stock items removed from the cart',
            'removed_items' => $removedItems,
        ]);
    }
}
