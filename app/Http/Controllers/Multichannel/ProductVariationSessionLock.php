<?php

namespace App\Http\Controllers\Multichannel;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductVariationSessionLock extends Controller
{
    public function index(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sortBy', 'post_modified'); // default sort field
        $sortOrder = $request->query('sortOrder', 'desc');    // default sort order
        $page = $request->query('page', 1);

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
        $directLimitProductIds = ProductMeta::whereIn('meta_key', ['max_quantity', 'min_quantity'])
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->pluck('post_id')
            ->toArray();

        $variationIdsWithLimits = ProductMeta::whereIn('meta_key', ['max_quantity_var', 'min_quantity_var'])
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->pluck('post_id')
            ->toArray();

        $parentProductIdsFromVariations = Product::whereIn('ID', $variationIdsWithLimits)
            ->pluck('post_parent')
            ->toArray();
        $allRelevantProductIds = array_unique(array_merge($directLimitProductIds, $parentProductIdsFromVariations));

        $query = Product::with([
            'meta' => function ($query) {
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
                        'sessions_limit_data',
                    ]);
            },
            'variations' => function ($query) {
                $query->select('ID', 'post_parent', 'post_title', 'post_name')
                    ->with([
                        'varients' => function ($query) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', [
                                    '_price',
                                    '_stock_status',
                                    '_stock',
                                    'max_quantity_var',
                                    'min_quantity_var',
                                    '_sku',
                                    '_thumbnail_id',
                                    'sessions_limit_data',
                                ]);
                        }
                    ]);
            },
            'thumbnail'
        ])
            ->whereIn('ID', $allRelevantProductIds)
            ->where('post_type', 'product')
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date');

        // Add search functionality
        if (!empty($searchTerm)) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('post_title', 'LIKE', "%{$searchTerm}%")
                    ->orWhereHas('meta', function ($q) use ($searchTerm) {
                        $q->where('meta_key', '_sku')
                            ->where('meta_value', 'LIKE', "%{$searchTerm}%");
                    });
            });
        }

        $products = $query->orderBy($sortBy, $sortOrder)
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status' => true,
            'products' => $products
        ]);
    }
    public function updateOrCreate(Request $request)
    {
        $isAdmin = false;
        $adminId = null;
        $adminName = 'Admin';

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $adminId = $user->id;
            $adminName = $user->name ?? 'Admin';

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
            'quantities.*.post_id' => 'required|integer',
            'quantities.*.session_limit' => 'nullable|array',
            'quantities.*.session_limit.*.session_limt_id' => 'nullable|integer',
            'quantities.*.session_limit.*.limit_session_start' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.session_limit.*.limit_session_end' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.session_limit.*.min_order_limit_per_user' => 'nullable|integer',
            'quantities.*.session_limit.*.max_order_limit_per_user' => 'nullable|integer',
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
            $metaKey = 'sessions_limit_data';

            $existingMeta = ProductMeta::where('post_id', $postId)->where('meta_key', $metaKey)->first();
            $existingSessions = [];

            if ($existingMeta) {
                $existingSessions = json_decode($existingMeta->meta_value, true) ?? [];
            }

            $existingIds = array_column($existingSessions, 'session_limt_id');
            $maxId = $existingIds ? max(array_filter($existingIds)) : 0;
            // strong logic for session limit
            if (!empty($quantity['session_limit']) && is_array($quantity['session_limit'])) {
                foreach ($quantity['session_limit'] as $newSession) {
                    $matched = false;

                    if (empty($newSession['session_limt_id'])) {
                        $maxId += 1;
                        $newSession['session_limt_id'] = $maxId;
                    }

                    $newStart = strtotime($newSession['limit_session_start'] ?? '');
                    $newEnd = strtotime($newSession['limit_session_end'] ?? '');

                    foreach ($existingSessions as $existingSession) {
                        $existingId = $existingSession['session_limt_id'] ?? null;

                        if ($existingId != $newSession['session_limt_id']) {
                            $existingStart = strtotime($existingSession['limit_session_start'] ?? '');
                            $existingEnd = strtotime($existingSession['limit_session_end'] ?? '');

                            if (
                                $newStart && $newEnd &&
                                $existingStart && $existingEnd &&
                                $newStart <= $existingEnd && $newEnd >= $existingStart
                            ) {
                                return response()->json([
                                    'status' => false,
                                    'message' => "Session for post_id {$postId->post_title} overlaps with an existing session between {$existingSession['limit_session_start']} and {$existingSession['limit_session_end']}."
                                ]);
                            }
                        }
                    }

                    $currentTime = now()->format('Y-m-d H:i:s');
                    $logEntry = [
                        'userID' => $adminId,
                        'message' => "This rule " . (empty($newSession['session_limt_id']) ? 'created' : 'updated') . " by {$adminName} at {$currentTime}",
                        'date' => $currentTime
                    ];
                    foreach ($existingSessions as &$existingSession) {
                        if (
                            isset($existingSession['session_limt_id']) &&
                            $existingSession['session_limt_id'] == $newSession['session_limt_id']
                        ) {
                            $existingSession = array_merge($existingSession, $newSession);
                            $existingSession['log_list'] = $existingSession['log_list'] ?? [];
                            $existingSession['log_list'][] = $logEntry;
                            $matched = true;
                            break;
                        }
                    }

                    if (!$matched) {
                        $newSession['log_list'] = [$logEntry];
                        $existingSessions[] = $newSession;
                    }
                }
            }
            if (!empty($quantity['type']) && isset($quantity['value'])) {
                ProductMeta::updateOrCreate(
                    [
                        'post_id' => $postId,
                        'meta_key' => $quantity['type'],
                    ],
                    [
                        'meta_value' => $quantity['value'],
                    ]
                );
            }

            ProductMeta::updateOrCreate(
                [
                    'post_id' => $postId,
                    'meta_key' => $metaKey,
                ],
                [
                    'meta_value' => json_encode($existingSessions),
                ]
            );
        }

        return response()->json(['status' => true, 'message' => 'Quantities updated successfully.']);
    }
    public function create(Request $request) {}
    public function show(Request $request) {}
    public function edit(Request $request) {}
    public function destroy(Request $request) {}

    public function getPurchaseLimitProductById(Request $request, $id)
    {
        $productId = $id;
        $logData = [];

        // Step 1: Get product or variation with meta + variations
        $product = Product::with(['meta', 'variations.varients'])->where('ID', $productId)
            ->orWhereHas('variations', function ($q) use ($productId) {
                $q->where('ID', $productId);
            })->first();

        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Product not found']);
        }

        $ids = [];

        // Step 2: Check for sessions_limit_data in product meta
        $productMeta = collect($product->meta ?? []);
        $hasSessionLimit = $productMeta->where('meta_key', 'sessions_limit_data')->isNotEmpty();
        if ($hasSessionLimit) {
            $ids[] = $product->ID;
        }

        // Step 3: Check each variation for session limits
        foreach ($product->variations ?? [] as $variation) {
            $variationMeta = collect($variation->meta ?? []);
            if ($variationMeta->where('meta_key', 'sessions_limit_data')->isNotEmpty()) {
                $ids[] = $variation->ID;
            }
        }

        // Step 4: Fetch all product_limit_session records for matched IDs
        $records = DB::table('product_limit_session')
            ->whereIn('product_variation_id', $ids)
            ->get();

        foreach ($records as $record) {
            $user = User::where('ID', $record->user_id)->first();

            if ($user) {
                $logData[] = [
                    'product_variation_id' => $record->product_variation_id,
                    'user_id' => $user->ID,
                    'name' => $user->user_login,
                    'email' => $user->user_email,
                    'capabilities' => $user->capabilities,
                    'account_no' => $user->account,
                    'order_count' => $record->order_count,
                    'session_id' => $record->session_id,
                    'blocked_attempts' => $record->blocked_attemps,
                    'blocked_time' => $record->blocked_attemp_time,
                    'log' => $record->log,
                    'last_updated' => $record->updated_at,
                ];
            }
        }

        return response()->json(['status' => true, 'logData' => $logData]);
    }
}
