<?php

namespace App\Http\Controllers;

use App\Models\DiscountRule;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\UserCoupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class DiscountRuleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user) {
                $discountRules = DiscountRule::where('enabled', 1)->where('deleted', 0)->get();
                try {
                    $existingCoupon = UserCoupon::where('email', $user->user_email)->where('canUse', true)->get();
                } catch (\Throwable $th) {
                    $existingCoupon = [];
                }
                
                // Merge the existing coupon data with the discount rules
                $discountRules = $discountRules->map(function ($discountRule) use ($existingCoupon) {
                    // Add 'existingCoupon' to each discount rule
                    $discountRule->existingCoupon = $existingCoupon;
                    return $discountRule;
                });
                return response()->json($discountRules);
            } else {
                return response()->json(['status' => 'failure', 'message' => 'You don\'t have any discount'], 401);
            }
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 401);
        }
    }

    public function singleDiscount(string $id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user) {
                $discountRules = DiscountRule::where('id',$id )->get();
                return response()->json(['status' => true, 'data'=>$discountRules]);
            } else {
                return response()->json(['status' => false, 'message' => 'You don\'t have any discount'], 401);
            }
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 401);
        }
    }



    public function show(Request $request, $id)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $product = Product::with([
            'meta',
            'categories.taxonomies',
            'categories.children',
            'categories.categorymeta'
        ])->where('id', $id)->firstOrFail();

        $metaData = $product->meta->map(function ($meta) {
            return [
                'id' => $meta->meta_id,
                'key' => $meta->meta_key,
                'value' => $meta->meta_value,
            ];
        });


        // $categories = $product->categories->map(function ($category) {
        //     return [
        //         'id' => $category->term_id,
        //         'name' => $category->name,
        //         'slug' => $category->slug,
        //         'taxonomy' => $category->taxonomies,
        //         'meta' => $category->categorymeta->pluck('meta_value', 'meta_key')->toArray(),
        //         'children' => $category->children,
        //     ];
        // });
        
        // $brands = $product->categories->filter(function ($category) {
        //     // Check if the category's taxonomy type is 'brand'
        //     return $this->getTaxonomyType($category->taxonomies) === 'brand';
        // })->map(function ($category) {
        //     return [
        //         'id' => $category->term_id,
        //         'name' => $category->name,
        //         'slug' => $category->slug,
        //         'taxonomy' => $category->taxonomies,
        //         'meta' => $category->categorymeta->pluck('meta_value', 'meta_key')->toArray(),
        //         'children' => $category->children,
        //     ];
        // });

        $thumbnailUrl = $this->getThumbnailUrl($product->ID);
        $price = $metaData->where('key', '_price')->first()['value'] ?? '';

        
        $priceTier = $user->price_tier ?? '';
        $variations = $this->getVariations($product->ID, $priceTier);
        $response = [
            'id' => $product->ID,
            'name' => $product->post_title,
            'slug' => $product->post_name,
            'permalink' => url('/product/' . $product->post_name),
            'type' => $product->post_type,
            'status' => $product->post_status,
            'min_quantity' => $metaData->where('key', 'min_quantity')->first()['value'] ?? false,
            'max_quantity' => $metaData->where('key', 'max_quantity')->first()['value'] ?? false,
            'sku' => $metaData->where('key', '_sku')->first()['value'] ?? '',
            'ad_price' => ProductMeta::where('post_id', $product->ID)->where('meta_key', $priceTier)->value('meta_value') ?? $this->getVariationsPrice($product->ID, $priceTier),
            'price' => $price ?? $metaData->where('key', '_regular_price')->first()['value'] ?? $metaData->where('key', '_price')->first()['value'] ?? null,
            'purchasable' => $product->post_status === 'publish',
            'catalog_visibility' => $metaData->where('key', '_visibility')->first()['value'] ?? 'visible',
            'tax_status' => $metaData->where('key', '_tax_status')->first()['value'] ?? 'taxable',
            'tax_class' => $metaData->where('key', '_tax_class')->first()['value'] ?? '',
            'stock_quantity' => $metaData->where('key', '_stock')->first()['value'] ?? null,
            'variations' => $variations,
            'thumbnail_url' => $thumbnailUrl,
            'stock_status' => $metaData->where('key', '_stock_status')->first()['value'] ?? 'instock',
        ];




        return response()->json($response);
    }
    private function getVariationsPrice($productId, $priceTier = '')
    {
        $variations = Product::where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->with('meta')
            ->get()
            ->map(function ($variation) use ($priceTier) {
                $metaData = $variation->meta->pluck('meta_value', 'meta_key')->toArray();
                $pattern = '/^(_regular_price|_price' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';
                $filteredMetaData = array_filter($metaData, function ($key) use ($pattern) {
                    return preg_match($pattern, $key);
                }, ARRAY_FILTER_USE_KEY);
                $adPrice = $metaData[$priceTier] ?? $metaData['_price'] ?? $metaData['_regular_price'] ?? null;

                return $adPrice;
            });
            $variations= $variations[0]??[];
        return $variations;
    }


    public function getTaxonomyType($taxonomy)
    {
        if ($taxonomy->taxonomy === 'product_cat') {
            return 'category';
        } elseif ($taxonomy->taxonomy === 'product_brand') {
            return 'brand';
        }
        return 'unknown';
    }

    private function getVariations($productId, $priceTier = '')
    {
        $variations = Product::where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->whereHas('meta', function ($query) {
                // Filter variations to include only those in stock
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->with('meta')
            ->get()
            ->map(function ($variation) use ($priceTier) {
                // Get meta data as an array
                $metaData = $variation->meta->pluck('meta_value', 'meta_key')->toArray();

                // Construct the regex pattern to include the price tier
                $pattern = '/^(_sku|attribute_.*|_stock|_regular_price|_price|_stock_status|max_quantity|min_quantity|mm_indirect_tax_type|_tax_class|mm_product_basis_1|mm_product_basis_2' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';

                // Filter meta data to include only the selected fields
                $filteredMetaData = array_filter($metaData, function ($key) use ($pattern) {
                    return preg_match($pattern, $key);
                }, ARRAY_FILTER_USE_KEY);

                // Determine the price to use based on price tier or fallback to regular price
                $adPrice = $metaData[$priceTier] ?? $metaData['_price'] ?? $metaData['_regular_price'] ?? null;

                return [
                    'id' => $variation->ID,
                    'date' => $variation->post_modified_gmt,
                    'meta' => $filteredMetaData,
                    'ad_price' => $adPrice,  // Include ad_price here
                    'thumbnail_url' => $this->getThumbnailUrl($variation->ID),  // Add variation thumbnail URL here
                ];
            });

        return $variations;
    }



    private function getThumbnailUrl($productId)
    {
        $thumbnailId = ProductMeta::where('post_id', $productId)->where('meta_key', '_thumbnail_id')->value('meta_value');
        if ($thumbnailId) {
            $url = Product::where('ID', $thumbnailId)->value('guid');
            if ($url) {
                return str_replace('http://localhost/ad', 'https://eadn-wc05-12948169.nxedge.io', $url);
            }
        }
        return null;
    }
}
