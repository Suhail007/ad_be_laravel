<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $orders = Order::
        where('post_type', 'shop_order')
            ->where('post_author', $user->ID)
            ->with(['items', 'items.meta', 'meta'])
            ->
            get();

        return response()->json($orders);
    }

    public function show($id)
    {
        $order = Order::with(['items', 'items.meta', 'meta'])->findOrFail($id);

        return response()->json($order);
    }
    public function createNewOrder(Request $request)
    {
       
        $orderData = $request->all();
        try {
            $user = JWTAuth::parseToken()->authenticate();
            DB::beginTransaction();
            $options = DB::select("SELECT option_value FROM wp_options WHERE option_name= 'wt_last_order_number'");
            $currentValue = (int)$options[0]->option_value;
            // dd($currentValue);
            $orderId = DB::table('wp_posts')->insertGetId([
                'post_author' => $user->ID,
                'post_date' => now(),
                'post_date_gmt' => now(),
                'post_content' => '',
                'post_title' => 'Order',
                'to_ping' => '',
                'pinged' => '',
                'post_content_filtered' => '',
                'post_excerpt' => '',
                'post_status' => 'wc-processing',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_name' => 'order-' . uniqid(),
                'post_modified' => now(),
                'post_modified_gmt' => now(),
                'post_type' => 'shop_order',
                'guid' => 'https://ad.phantasm.solutions/?post_type=shop_order&p=' . uniqid(),
            ]);
            $metaData = [
                ['post_id' => $orderId, 'meta_key' => '_billing_first_name', 'meta_value' => $orderData['billing']['first_name']],
                ['post_id' => $orderId, 'meta_key' => '_billing_last_name', 'meta_value' => $orderData['billing']['last_name']],
                ['post_id' => $orderId, 'meta_key' => '_billing_address_1', 'meta_value' => $orderData['billing']['address_1']],
                ['post_id' => $orderId, 'meta_key' => '_billing_address_2', 'meta_value' => $orderData['billing']['address_2']],
                ['post_id' => $orderId, 'meta_key' => '_billing_city', 'meta_value' => $orderData['billing']['city']],
                ['post_id' => $orderId, 'meta_key' => '_billing_state', 'meta_value' => $orderData['billing']['state']],
                ['post_id' => $orderId, 'meta_key' => '_billing_postcode', 'meta_value' => $orderData['billing']['postcode']],
                ['post_id' => $orderId, 'meta_key' => '_billing_country', 'meta_value' => $orderData['billing']['country']],
                ['post_id' => $orderId, 'meta_key' => '_billing_email', 'meta_value' => $orderData['billing']['email']],
                ['post_id' => $orderId, 'meta_key' => '_billing_phone', 'meta_value' => $orderData['billing']['phone']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_first_name', 'meta_value' => $orderData['shipping']['first_name']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_last_name', 'meta_value' => $orderData['shipping']['last_name']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_address_1', 'meta_value' => $orderData['shipping']['address_1']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_address_2', 'meta_value' => $orderData['shipping']['address_2']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_city', 'meta_value' => $orderData['shipping']['city']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_state', 'meta_value' => $orderData['shipping']['state']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_postcode', 'meta_value' => $orderData['shipping']['postcode']],
                ['post_id' => $orderId, 'meta_key' => '_shipping_country', 'meta_value' => $orderData['shipping']['country']],
                ['post_id' => $orderId, 'meta_key' => '_payment_method', 'meta_value' => $orderData['payment_method']],
                ['post_id' => $orderId, 'meta_key' => '_payment_method_title', 'meta_value' => $orderData['payment_method_title']],
                ['post_id' => $orderId, 'meta_key' => '_transaction_id', 'meta_value' => uniqid()],
                ['post_id' => $orderId, 'meta_key' => '_order_total', 'meta_value' => $orderData['shipping_lines'][0]['total'] + array_reduce($orderData['line_items'], function ($carry, $item) {
                    return $carry + $item['quantity'] * $item['price'];
                }, 0)],
                ['post_id' => $orderId, 'meta_key' => '_order_currency', 'meta_value' => 'USD'],
                ['post_id' => $orderId, 'meta_key' => '_order_key', 'meta_value' => 'wc_order_' . uniqid()],
                ['post_id' => $orderId, 'meta_key' => '_customer_user', 'meta_value' => $user->ID],  
                ['post_id' => $orderId, 'meta_key' => '_created_via', 'meta_value' => 'checkout'],
                ['post_id' => $orderId, 'meta_key' => '_order_stock_reduced', 'meta_value' => 'yes'],
                ['post_id' => $orderId, 'meta_key' => '_billing_address_index', 'meta_value' => implode(' ', $orderData['billing'])],
                ['post_id' => $orderId, 'meta_key' => '_shipping_address_index', 'meta_value' => implode(' ', $orderData['shipping'])],
                ['post_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $currentValue],
            ];
            foreach ($metaData as $meta) {
                DB::table('wp_postmeta')->insert($meta);
            }
            $totalAmount = $orderData['shipping_lines'][0]['total'] + array_reduce($orderData['line_items'], function ($carry, $item) {
                return $carry + $item['quantity'] * $item['price'];
            }, 0);
            $productCount = count($orderData['line_items']);
            foreach ($orderData['line_items'] as $item) {
                $orderItemId = DB::table('wp_woocommerce_order_items')->insertGetId([
                    'order_id' => $orderId,
                    'order_item_name' => $item['name'],
                    'order_item_type' => 'line_item'
                ]);

                $itemMeta = [
                    ['order_item_id' => $orderItemId, 'meta_key' => '_product_id', 'meta_value' => $item['product_id']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_variation_id', 'meta_value' => $item['variation_id'] ?? 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_qty', 'meta_value' => $item['quantity']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_tax_class', 'meta_value' => $item['tax_class'] ?? ''],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal', 'meta_value' => $item['quantity'] * $item['price']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal_tax', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_total', 'meta_value' => $item['quantity'] * $item['price']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax_data', 'meta_value' => serialize(['total' => [], 'subtotal' => []])],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j1', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j1', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j2', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j2', 'meta_value' => 0],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_priced', 'meta_value' => 'yes'],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_role', 'meta_value' => $item['wholesale_role']],
                    ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_price', 'meta_value' => $item['wholesale_price']],
                ];

                foreach ($itemMeta as $meta) {
                    DB::table('wp_woocommerce_order_itemmeta')->insert($meta);
                    if ($item['variation_id']) {
                        $productMeta = ProductMeta::where('post_id', $item['variation_id'])->where('meta_key', '_stock')->first();
                        if ($productMeta) {
                            $productMeta->meta_value -= $item['quantity'];
                            $productMeta->save();
                        }
                    } else {
                        $productMeta = ProductMeta::where('post_id', $item['product_id'])->where('meta_key', '_stock')->first();
                        if ($productMeta) {
                            $productMeta->meta_value -= $item['quantity'];
                            $productMeta->save();
                        }
                    }
                    Cart::where('user_id', $request->user()->id)
                        ->where('product_id', $item['product_id'])
                        ->where('variation_id', $item['variation_id'] ?? null)
                        ->delete();
                }
                DB::table('wp_wc_order_product_lookup')->insert([
                    'order_item_id' => $orderItemId,
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? 0,
                    'customer_id' => $user->ID, 
                    'date_created' => now(),
                    'product_qty' => $item['quantity'],
                    'product_net_revenue' => $item['quantity'] * $item['price'],
                    'product_gross_revenue' => $item['quantity'] * $item['price'],
                ]);
            }
            DB::table('wp_wc_orders')->insert([
                'id' => $orderId,
                'status' => 'wc-processing',
                'currency' => 'USD',
                'type' => 'shop_order',
                'tax_amount' => 0,
                'total_amount' => $totalAmount,
                'customer_id' => $user->ID, 
                'billing_email' => $orderData['billing']['email'],
                'date_created_gmt' => now(),
                'date_updated_gmt' => now(),
                'parent_order_id' => 0,
                'payment_method' => $orderData['payment_method'],
                'payment_method_title' => $orderData['payment_method_title'],
                'transaction_id' => uniqid(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'customer_note' => ''
            ]);
            $wp_wc_order_meta = [
                ['order_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $currentValue],
                ['order_id' => $orderId, 'meta_key' => '_wwpp_order_type', 'meta_value' => 'wholesale'],
                ['order_id' => $orderId, 'meta_key' => '_wwpp_wholesale_order_type', 'meta_value' => 'mm_price_2'],
                ['order_id' => $orderId, 'meta_key' => 'wwp_wholesale_role', 'meta_value' => 'mm_price_2'],
                [
                    'order_id' => $orderId,
                    'meta_key' => '_shipping_address_index',
                    'meta_value' => (isset($orderData['shipping']['first_name']) ? $orderData['shipping']['first_name'] . ' ' : '') .
                        (isset($orderData['shipping']['address_1']) ? $orderData['shipping']['address_1'] . ' ' : '') .
                        (isset($orderData['shipping']['city']) ? $orderData['shipping']['city'] . ' ' : '') .
                        (isset($orderData['shipping']['state']) ? $orderData['shipping']['state'] . ' ' : '') .
                        (isset($orderData['shipping']['postcode']) ? $orderData['shipping']['postcode'] : '')
                ],
            ];
            DB::table('wp_wc_order_meta')->insert($wp_wc_order_meta);
            DB::table('wp_wc_order_addresses')->insert([
                [
                    'order_id' => $orderId,
                    'address_type' => 'billing',
                    'first_name' => $orderData['billing']['first_name'],
                    'last_name' => $orderData['billing']['last_name'],
                    'company' => '',
                    'address_1' => $orderData['billing']['address_1'],
                    'address_2' => $orderData['billing']['address_2'],
                    'city' => $orderData['billing']['city'],
                    'state' => $orderData['billing']['state'],
                    'postcode' => $orderData['billing']['postcode'],
                    'country' => $orderData['billing']['country'],
                    'email' => $orderData['billing']['email'],
                    'phone' => $orderData['billing']['phone']
                ],
                [
                    'order_id' => $orderId,
                    'address_type' => 'shipping',
                    'first_name' => $orderData['shipping']['first_name'],
                    'last_name' => $orderData['shipping']['last_name'],
                    'company' => '',
                    'address_1' => $orderData['shipping']['address_1'],
                    'address_2' => $orderData['shipping']['address_2'],
                    'city' => $orderData['shipping']['city'],
                    'state' => $orderData['shipping']['state'],
                    'postcode' => $orderData['shipping']['postcode'],
                    'country' => $orderData['shipping']['country'],
                    'email' => $orderData['billing']['email'], 
                    'phone' => $orderData['billing']['phone'] 
                ]
            ]);
            DB::table('wp_wc_order_stats')->insert([
                'order_id' => $orderId,
                'parent_id' => 0,
                'status' => 'wc-processing',
                'date_created' => now(),
                'date_created_gmt' => now(),
                'num_items_sold' => $productCount,
                'total_sales' => $totalAmount,
                'tax_total' => 0,
                'shipping_total' => $orderData['shipping_lines'][0]['total'],
                'net_total' => $totalAmount,
                'returning_customer' => 0,  // Assuming it's a new customer
                'customer_id' => $user->ID,  // Assuming customer ID is 1
                'date_paid' => null, 
                'date_completed' => null,
            ]);
            $orderNotes = [
                [
                    'comment_post_ID' => $orderId,
                    'comment_author' => 'WooCommerce',
                    'comment_author_email' => '',
                    'comment_author_url' => '',
                    'comment_author_IP' => $request->ip(),
                    'comment_date' => now(),
                    'comment_date_gmt' => now(),
                    'comment_content' => 'Order status changed from Pending payment to Processing.',
                    'comment_karma' => 0,
                    'comment_approved' => 1,
                    'comment_agent' => $request->userAgent(),
                    'comment_type' => 'order_note',
                    'comment_parent' => 0,
                    'user_id' => 0,
                ],
                [
                    'comment_post_ID' => $orderId,
                    'comment_author' => 'WooCommerce',
                    'comment_author_email' => '',
                    'comment_author_url' => '',
                    'comment_author_IP' => $request->ip(),
                    'comment_date' => now(),
                    'comment_date_gmt' => now(),
                    'comment_content' => 'NMI charge complete (Charge ID: 9662XXX234)',
                    'comment_karma' => 0,
                    'comment_approved' => 1,
                    'comment_agent' => $request->userAgent(),
                    'comment_type' => 'order_note',
                    'comment_parent' => 0,
                    'user_id' => 0,
                ],
            ];
            foreach ($orderNotes as $note) {
                DB::table('wp_comments')->insert($note);
            }
            $newValue = $currentValue + 1;
            DB::update("UPDATE wp_options SET option_value = ? WHERE option_name = 'wt_last_order_number'", [$newValue]);
            DB::commit();
            //send success mail to admin
            return response()->json(['message' => 'Order created successfully', 'order_id' => $orderId], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            //send failure mail to admin to take action
            return response()->json(['error' => 'Failed to create order: ' . $e->getMessage()], 500);
        }
    }
}
