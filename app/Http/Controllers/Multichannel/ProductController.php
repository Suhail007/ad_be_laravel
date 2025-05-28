<?php

namespace App\Http\Controllers\Multichannel;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductController extends Controller
{
    public function getProductVariation(Request $request,$id=null){
        $isAdmin = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $data = $user->capabilities;
            foreach ($data as $key => $value) {
                if ($key == 'administrator') {
                    $isAdmin = true;
                }
            }
        } catch (\Throwable $th) {
        
        }
        
        if(!$isAdmin){
            return response()->json(['status'=>false, 'message'=>'Hey you are not Allowed']);
        }
        $priceTier ='_price';
        $product = Product::with([
            'meta' => function ($query) use ($priceTier) {
                $query->select('meta_id','post_id', 'meta_key', 'meta_value')
                    ->whereIn('meta_key', ['_price', '_stock', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery','min_quantity','max_quantity', $priceTier,'limit_session_start','limit_session_end','min_order_limit_per_user','max_order_limit_per_user']);
            },
            'variations' => function ($query) use ($priceTier) {
                $query->select('ID', 'post_parent', 'post_title', 'post_name')
                    ->with([
                        'varients' => function ($query) use ($priceTier) {
                            $query->select('meta_id','post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_stock', '_sku', '_thumbnail_id', $priceTier,'max_quantity_var','min_quantity_var','limit_session_start','limit_session_end','min_order_limit_per_user','max_order_limit_per_user'])
                                // ->orWhere(function ($query) {
                                //     $query->where('meta_key', 'like', 'attribute_%'); // slow down 
                                // })
                                ;
                        }
                    ]);
            },
            'thumbnail'
        ])
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->where('post_type', 'product')
            ->where('ID',$id)
            ->first();
            return response()->json(['status'=>true,'data'=>$product]);
    }

    public function updateQuantity(Request $request){
        $isAdmin = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $data = $user->capabilities;
            foreach ($data as $key => $value) {
                if ($key == 'administrator') {
                    $isAdmin = true;
                }
            }
        } catch (\Throwable $th) {
        
        }
        
        if(!$isAdmin){
            return response()->json(['status'=>false, 'message'=>'Hey you are not Allowed']);
        }
        $validate = Validator::make($request->all(), [
            'quantities' => 'required|array',
            'quantities.*.value' => 'required|numeric',
            'quantities.*.type' => 'required|in:max_quantity,min_quantity,max_quantity_var,min_quantity_var',
            'quantities.*.post_id' => 'required|integer', // Assuming post_id is required
            'quantities.*.limit_session_start' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.limit_session_end' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.min_order_limit_per_user' => 'nullable|integer',
            'quantities.*.max_order_limit_per_user' => 'nullable|integer',
        ]);
        if ($validate->fails()) {
            $errors = $validate->errors()->toArray();
            $formattedErrors = [];
            foreach ($errors as $key => $errorMessages) {
                $formattedErrors[] = [
                    'field' => $key,
                    'messages' => $errorMessages
                ];
            }
            return response()->json([
                'status' => false,
                'message' => $formattedErrors
            ]);
        }
        $data = $request->all();

        foreach ($data['quantities'] as $quantity) {
            $postId = $quantity['post_id'];
            $optionalDelete = false;
            $metaFields = [
                $quantity['type'] => $quantity['value'],
            ];

            if (!empty($quantity['limit_session_start'])) {
                $metaFields['limit_session_start'] = $quantity['limit_session_start'];
            }

            if (!empty($quantity['limit_session_end'])) {
                $metaFields['limit_session_end'] = $quantity['limit_session_end'];
                $optionalDelete = true;
            }

            if (!empty($quantity['min_order_limit_per_user'])) {
                $metaFields['min_order_limit_per_user'] = $quantity['min_order_limit_per_user'];
            }

            if (!empty($quantity['max_order_limit_per_user'])) {
                $metaFields['max_order_limit_per_user'] = $quantity['max_order_limit_per_user'];
                $optionalDelete = true;
            }
            foreach ($metaFields as $metaKey => $metaValue) {
                ProductMeta::updateOrCreate(
                    [
                        'post_id' => $postId,
                        'meta_key' => $metaKey,
                    ],
                    [
                        'meta_value' => $metaValue,
                    ]
                );
            }
            // optional: delete user-specific limit sessions when limits are changed
            if($optionalDelete){
                DB::table('product_limit_session')->where('product_variation_id', $postId)->delete();
            }
        }

        return response()->json(['status' => true, 'message' => 'Quantities updated successfully.']);
    }
    
    public function getPurchaseLimitProduct(Request $request){
        $searchTerm = $request->input('searchTerm', '');
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);
            
        $sortBy = $request->query('sortBy', 'post_modified'); // default sort field
        $sortOrder = $request->query('sortOrder', 'desc');    // default sort order

        $isAdmin = false;

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];

            $isAdmin = isset($capabilities['administrator']);
        } catch (\Throwable $th) {
        }

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'Hey, you are not allowed']);
        }

        $priceTier = '_price';
        $productIds = ProductMeta::whereIn('meta_key', ['max_quantity', 'min_quantity'])
        ->whereNotNull('meta_value')
        ->where('meta_value', '!=', '')
        ->pluck('post_id')
        ->merge(
            ProductMeta::whereIn('meta_key', ['max_quantity_var', 'min_quantity_var'])
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', '')
                ->pluck('post_id')
        )
        ->unique()
        ->toArray();


        $products = Product::with([
        'meta' => function ($query)  {
        $query->select('post_id', 'meta_key', 'meta_value')
            ->whereIn('meta_key', [
                '_price',
                '_stock_status',
                '_stock',
                'max_quantity',
                'min_quantity',
                '_sku',
                '_thumbnail_id',
                '_product_image_gallery',
            ]);
        },
        'variations' => function ($query)  {
        $query->select('ID', 'post_parent', 'post_title', 'post_name')
            ->with([
                'varients' => function ($query)  {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', [
                            '_price',
                            '_stock_status',
                            '_stock',
                            'max_quantity_var',
                            'min_quantity_var',
                            '_sku',
                            '_thumbnail_id',
                        ]);
                }
            ]);
        },
        'thumbnail'
        ])
        ->whereIn('ID', $productIds)
        ->where('post_type', 'product')
        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
        ->orderBy($sortBy, $sortOrder)
        ->paginate($perPage, ['*'], 'page', $page);
        return response()->json([
            'status' => true,
            'products' => $products
        ]);
    }

    public function removePurchaseLimit($id){
        $isAdmin = false;

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            $isAdmin = isset($capabilities['administrator']);
        } catch (\Throwable $th) {}

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'You are not allowed']);
        }
        $productKeys = ['max_quantity', 'min_quantity', 'limit_session_start', 'limit_session_end', 'min_order_limit_per_user', 'max_order_limit_per_user'];
        $variantKeys = ['max_quantity_var', 'min_quantity_var', 'limit_session_start', 'limit_session_end', 'min_order_limit_per_user', 'max_order_limit_per_user'];
        $productDeleted = ProductMeta::where('post_id', $id)
            ->whereIn('meta_key', $productKeys)
            ->delete();

        $variantIds = Product::where('post_parent', $id)
            ->where('post_type', 'product_variation')
            ->pluck('ID')
            ->toArray();
        if($variantIds){
            $variantDeleted = ProductMeta::whereIn('post_id', $variantIds)
                ->whereIn('meta_key', $variantKeys)
                ->delete();
            return response()->json(['status' => true, 'message' => 'Purchase limits removed of variations']);
        } else {
            return response()->json(['status' => true, 'message' => 'Purchase limits removed ']);
        }

    }

    public function searchPurchaseLimitProduct(Request $request){
        $searchTerm = $request->input('searchTerm', '');
        $perPage = $request->query('perPage', 15);
        $page = $request->query('page', 1);
        $sortBy = $request->query('sortBy', 'post_modified');
        $sortOrder = $request->query('sortOrder', 'desc');
        $isAdmin = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            $isAdmin = isset($capabilities['administrator']);
        } catch (\Throwable $th) {}
        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'You are not allowed']);
        }
        $regexPattern = '';
        if (!empty($searchTerm)) {
            $searchWords = preg_split('/\s+/', $searchTerm);
            $regexPattern = implode('.*', array_map(function ($word) {
                return "(?=.*" . preg_quote($word) . ")";
            }, $searchWords));
        }
        if(!empty($searchTerm)){
            $products = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', [
                            '_price', '_stock_status', '_stock',
                            'max_quantity', 'min_quantity', '_sku',
                            '_thumbnail_id', '_product_image_gallery',
                        ]);
                },
                'variations' => function ($query) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with(['varients' => function ($query) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', [
                                    '_price', '_stock_status', '_stock',
                                    'max_quantity_var', 'min_quantity_var',
                                    '_sku', '_thumbnail_id'
                                ]);
                        }]);
                },
                'thumbnail'
            ])
            ->where('post_type', 'product')
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->where(function ($query) {
                $query->whereHas('meta', function ($q) {
                    $q->whereIn('meta_key', ['max_quantity', 'min_quantity'])
                    ->where('meta_value', '!=', '');
                })
                ->orWhereHas('variations.varients', function ($q) {
                    $q->whereIn('meta_key', ['max_quantity_var', 'min_quantity_var'])
                    ->where('meta_value', '!=', '');
                });
            })
            ->when($searchTerm, function ($query) use ($regexPattern) {
                $query->where(function ($q) use ($regexPattern) {
                    $q->where('post_title', 'REGEXP', $regexPattern)
                    ->orWhereHas('meta', function ($metaQuery) use ($regexPattern) {
                        $metaQuery->where('meta_key', '_sku')
                                    ->where('meta_value', 'REGEXP', $regexPattern);
                    });
                });
            })
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

            return response()->json([
                'status' => true,
                'products' => $products
            ]);
        } else {
            $priceTier = '_price';
            $productIds = ProductMeta::whereIn('meta_key', ['max_quantity', 'min_quantity'])
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->pluck('post_id')
            ->merge(
                ProductMeta::whereIn('meta_key', ['max_quantity_var', 'min_quantity_var'])
                    ->whereNotNull('meta_value')
                    ->where('meta_value', '!=', '')
                    ->pluck('post_id')
            )
            ->unique()
            ->toArray();
            $products = Product::with([
            'meta' => function ($query)  {
            $query->select('post_id', 'meta_key', 'meta_value')
                ->whereIn('meta_key', [
                    '_price',
                    '_stock_status',
                    '_stock',
                    'max_quantity',
                    'min_quantity',
                    '_sku',
                    '_thumbnail_id',
                    '_product_image_gallery',
                    'limit_session_start',
                    'limit_session_end',
                    'min_order_limit_per_user',
                    'max_order_limit_per_user',
                ]);
            },
            'variations' => function ($query)  {
            $query->select('ID', 'post_parent', 'post_title', 'post_name')
                ->with([
                    'varients' => function ($query)  {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', [
                                '_price',
                                '_stock_status',
                                '_stock',
                                'max_quantity_var',
                                'min_quantity_var',
                                '_sku',
                                '_thumbnail_id',
                                'limit_session_start',
                                'limit_session_end',
                                'min_order_limit_per_user',
                                'max_order_limit_per_user',
                            ]);
                    }
                ]);
            },
            'thumbnail'
            ])
            ->whereIn('ID', $productIds)
            ->where('post_type', 'product')
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage, ['*'], 'page', $page);
            return response()->json([
                'status' => true,
                'products' => $products
            ]);
        }
    }
}
