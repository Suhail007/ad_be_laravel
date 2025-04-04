<?php

namespace App\Http\Controllers;

use App\Models\DiscountRule;
use App\Models\Product;
use App\Models\ProductMeta;
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
                
                // $this->show($user, $id=0);
                return response()->json($discountRules);
            } else {
                return response()->json(['status' => 'failure', 'message' => 'You don\'t have any discount'], 401);
            }
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 401);
        }
    }
    public function offers(Request $request)
{
    $discountRules = DiscountRule::where('enabled', 1)
        ->where('deleted', 0)
        ->orderBy('priority', 'desc')
        ->get();

    $offers = [];

    foreach ($discountRules as $rule) {
        $filters = $rule->filters ?? [];
        $filterData = [];
        foreach ($filters as $filter) {
            if (!empty($filter['type']) && !empty($filter['value'])) {
                $filterData[] = [
                    'type' => $filter['type'],
                    'value' => $filter['value']
                ];
            }
        }

        // Add offer details to the list
        $offers[] = [
            'id' => $rule->id,
            'title' => $rule->title,
            'priority' => $rule->priority,
            'discount_type' => $rule->discount_type,
            // 'filters' => $filterData,
            'date_from' => $rule->date_from,
            'date_to' => $rule->date_to,
            'created_on' => $rule->created_on
        ];
    }

    return response()->json(['offers' => $offers]);
}

    public function offer(Request $request, $id)
{
    // Fetch the discount rule
    $discountRule = DiscountRule::where('enabled', 1)
        ->where('deleted', 0)
        ->where('id', $id)
        ->first();

    if (!$discountRule) {
        return response()->json(['error' => 'Offer not found'], 404);
    }

    $filters = [];
    $auth = false;
    $priceTier = '_price';

    // Authenticate user and set price tier
    try {
        $user = JWTAuth::parseToken()->authenticate();
        if ($user) {
            $auth = true;
            $priceTier = $user->price_tier ?? '_price';
        }
    } catch (\Throwable $th) {
        // User is not authenticated
    }

    // Parse filters
    $filterData = $discountRule->filters ?? [];
    foreach ($filterData as $filter) {
        if (!empty($filter['type']) && !empty($filter['value'])) {
            $filters[] = [
                'type' => $filter['type'],
                'value' => $filter['value']
            ];
        }
    }

    // Fetch products based on filters
    $products = Product::with([
        'meta' => function ($query) use ($priceTier) {
            $query->select('post_id', 'meta_key', 'meta_value')
                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', $priceTier]);
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
        },
        'variations' => function ($query) use ($priceTier) {
            $query->select('ID', 'post_parent', 'post_title', 'post_name')
                ->with([
                    'varients' => function ($query) use ($priceTier) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier])
                            ->orWhere('meta_key', 'like', 'attribute_%');
                    }
                ]);
        },
        'thumbnail'
    ])
        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
        ->where('post_type', 'product')
        ->where('post_status', 'publish')
        ->whereHas('meta', function ($query) {
            $query->where('meta_key', '_stock_status')
                ->where('meta_value', 'instock');
        });

    // Apply filter conditions
    if (!empty($filters)) {
        foreach ($filters as $filter) {
            if ($filter['type'] === 'products') {
                $products->whereIn('ID', $filter['value']);
            } elseif ($filter['type'] === 'product_category') {
                $products->whereHas('categories', function ($query) use ($filter) {
                    $query->whereIn('wp_terms.term_id', $filter['value']);
                });
            }
        }
    }

    // Apply category visibility filter for unauthenticated users
    if (!$auth) {
        $products->whereDoesntHave('categories.categorymeta', function ($query) {
            $query->where('meta_key', 'visibility')
                ->where('meta_value', 'protected');
        });
    }

    // Fetch the final product list
    $products = $products->get();

    // Return response
    return response()->json([
        'offer' => [
            'id' => $discountRule->id,
            'title' => $discountRule->title,
            'priority' => $discountRule->priority,
            'discount_type' => $discountRule->discount_type,
            'filters' => $filters,
            'date_from' => $discountRule->date_from,
            'date_to' => $discountRule->date_to,
            'created_on' => $discountRule->created_on
        ],
        'products' => $products
    ]);
}

public function bxgyOffers(Request $request)
{
    $x = $request->query('x', null);
$y = $request->query('y', null);

$x = is_numeric($x) ? (int)$x : null;
$y = is_numeric($y) ? (int)$y : null;

    $discountRules = DiscountRule::where('enabled', 1)
        ->where('deleted', 0)
        ->where('discount_type', 'wdr_buy_x_get_y_discount')
        ->orderBy('priority', 'desc')
        ->get();

    $offers = [];

    foreach ($discountRules as $rule) {
        $bxgyData = $rule->buy_x_get_y_adjustments ?? [];
        if (isset($bxgyData['ranges']) && is_array($bxgyData['ranges'])) {
            foreach (array_values($bxgyData['ranges']) as $range) {
                $buyQty = isset($range['from']) ? (int) $range['from'] : null;
                $freeQty = isset($range['free_qty']) ? (int) $range['free_qty'] : null;

                if ($buyQty ||   $freeQty )
{
                    $offers[] = [
                        'id' => $rule->id,
                        'title' => $rule->title,
                        'priority' => $rule->priority,
                        'discount_type' => $rule->discount_type,
                        // 'filters' => $filterData,
                        'date_from' => $rule->date_from,
                        'date_to' => $rule->date_to,
                        'created_on' => $rule->created_on
                    ];
                }
            }
        }
    }

    return response()->json(['offers' => $offers]);
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
