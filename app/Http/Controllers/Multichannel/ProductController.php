<?php

namespace App\Http\Controllers\Multichannel;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductController extends Controller
{
    public function getProductVariation(Request $request,$id=null)
    {
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
                    ->whereIn('meta_key', ['_price', '_stock', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery','min_quantity','max_quantity', $priceTier]);
            },
            'variations' => function ($query) use ($priceTier) {
                $query->select('ID', 'post_parent', 'post_title', 'post_name')
                    ->with([
                        'varients' => function ($query) use ($priceTier) {
                            $query->select('meta_id','post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_stock', '_sku', '_thumbnail_id', $priceTier,'max_quantity_var','min_quantity_var'])
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

    public function updateQuantity(Request $request)
    {
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

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'Hey you are not Allowed']);
        }
        $validate = Validator::make($request->all(), [
            'quantities' => 'required|array',
            'quantities.*.value' => 'required|numeric',
            'quantities.*.type' => 'required|in:max_quantity,min_quantity,max_quantity_var,min_quantity_var',
            'quantities.*.post_id' => 'required|integer', // Assuming post_id is required
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
            // Check if meta already exists with meta_id and meta_key (optionally you could also add post_id to prevent conflicts)
            $meta = ProductMeta::updateOrCreate(
                [
                    'meta_key' => $quantity['type'],
                    'post_id' => $quantity['post_id'],  // Adding post_id to prevent conflicts with other entries
                ],
                [
                    'meta_value' => $quantity['value'],
                ]
            );
        }

        return response()->json(['status' => true, 'message' => 'Quantities updated successfully.']);
    }

    public function getPurchaseLimitProduct(Request $request)
    {
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
}
