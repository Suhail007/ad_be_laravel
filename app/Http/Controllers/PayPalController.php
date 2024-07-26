<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Checkout;
use App\Models\OrderItemMeta;
use App\Models\OrderMeta;
use App\Models\ProductMeta;
use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PayPal\Api\Amount;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use Tymon\JWTAuth\Facades\JWTAuth;

class PayPalController extends Controller
{
    private $security_key;
    public function __construct()
    {
        $this->security_key = config('services.nmi.security');
    }

    private function validateBilling($billingInformation)
    {
        $validBillingKeys = [
            "first_name",
            "last_name",
            "company",
            "address1",
            "address2",
            "city",
            "state",
            "zip",
            "country",
            "phone",
            "fax",
            "email"
        ];

        foreach ($billingInformation as $key => $value) {
            if (!in_array($key, $validBillingKeys)) {
                throw new Exception("Invalid key provided in billingInformation. '{$key}' is not a valid billing parameter.");
            }
        }
    }
    

    private function validateShipping($shippingInformation)
    {
        $validShippingKeys = [
            "shipping_first_name",
            "shipping_last_name",
            "shipping_company",
            "shipping_address1",
            "address2",
            "shipping_city",
            "shipping_state",
            "shipping_zip",
            "shipping_country",
            "shipping_email"
        ];

        foreach ($shippingInformation as $key => $value) {
            if (!in_array($key, $validShippingKeys)) {
                throw new Exception("Invalid key provided in shippingInformation. '{$key}' is not a valid shipping parameter.");
            }
        }
    }

    private function doSale($amount, $payment_token, $billing, $shipping)
    {
        $requestOptions = [
            'type' => 'sale',
            'amount' => $amount,
            'payment_token' => $payment_token
        ];
        $requestOptions = array_merge($requestOptions, $billing, $shipping);

        return $requestOptions;
    }

    private function _doRequest($postData)
    {
        $hostName = "secure.nmi.com";
        $path = "/api/transact.php";
        $client = new Client();

        $postData['security_key'] = config('services.nmi.security');
        $postUrl = "https://{$hostName}{$path}";

        try {
            $response = $client->post($postUrl, [
                'form_params' => $postData,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);

            parse_str($response->getBody(), $responseArray);

            $parsedResponseCode = (int)$responseArray['response_code'];
            $status = in_array($parsedResponseCode, [100, 200]);

            $paydata = [
                'status' => $status,
                'date' => $response->getHeaderLine('Date'),
                'responsetext' => $responseArray['responsetext'],
                'authcode' => $responseArray['authcode'] ?? '',
                'transactionid' => $responseArray['transactionid'] ?? 'failed',
                'avsresponse' => $responseArray['avsresponse'] ?? 'N',
                'cvvresponse' => $responseArray['cvvresponse'] ?? 'N',
                'description' => $response->getBody()->getContents(),
                'response_code' => $parsedResponseCode,
                'type' => $responseArray['type'] ?? ''
            ];

            return $paydata;
        } catch (Exception $e) {
            throw new Exception("Error: " . $e->getMessage());
        }
    }

    public function processPayment(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $agent = $request->userAgent() ?? "Unknown Device";
        $ip = $request->ip() ?? "0.0.0.0";
        $checkout = Checkout::where('user_id', $user->ID)->first();
        $billingInfo = $checkout->billing;
        $shippingInfo = $checkout->shipping;
        $amount = $request->input('amount');
        $lineItems = $request->input('line_items');
        $paytype = $request->input('paymentType');
        $amount = $request->input('amount');
        $order_type = $request->input('order_type');
        $order_role = $request->input('order_role');
        $order_wholesale_role = $request->input('order_role'); // $request->input('order_wholesale_role');
        $shippingLines = $request->input('shipping_lines');

        $paytype = $request->input('paymentType');


        if ($paytype == 'card') {
            $payment_token = $request->input('payment_token');
            try {
                // $this->validateBilling($billingInfo);
                // $this->validateShipping($shippingInfo);

                $checkout->update(
                    [
                        'total' => $shippingLines[0]['total'] + $amount,
                        'extra' => $lineItems,
                        'paymentType' => $paytype,
                    ]
                );

                $saleData = $this->doSale($shippingLines[0]['total'] + $amount, $payment_token, $billingInfo, $shippingInfo);
                $paymentResult = $this->_doRequest($saleData);

                if (!$paymentResult['status']) {
                    return response()->json([
                        'status' => false,
                        'message' => $paymentResult,
                        'uniqueId' => null
                    ], 200);
                }
                $orderData = Checkout::where('user_id', $user->ID)->first();
                try {
                    DB::beginTransaction();
                    $options = DB::select("SELECT option_value FROM wp_options WHERE option_name= 'wt_last_order_number'");
                    $currentValue = (int)$options[0]->option_value;
                    $newValue = $currentValue + 1;
                    DB::update("UPDATE wp_options SET option_value = ? WHERE option_name = 'wt_last_order_number'", [$newValue]);
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
                        ['post_id' => $orderId, 'meta_key' => '_payment_method', 'meta_value' => $orderData['paymentType']],
                        ['post_id' => $orderId, 'meta_key' => '_payment_method_title', 'meta_value' => 'NMI Payment on Card'], //$orderData['payment_method_title']],
                        ['post_id' => $orderId, 'meta_key' => '_transaction_id', 'meta_value' => $paymentResult['data']['transactionid']],
                        ['post_id' => $orderId, 'meta_key' => '_order_total', 'meta_value' => $shippingLines[0]['total'] + $amount], //$orderData['shipping_lines'][0]['total'] + array_reduce($orderData['line_items'], function ($carry, $item) {return $carry + $item['quantity'] * $item['product_price'];}, 0)],
                        ['post_id' => $orderId, 'meta_key' => '_order_currency', 'meta_value' => 'USD'],
                        ['post_id' => $orderId, 'meta_key' => '_order_key', 'meta_value' => 'wc_order_' . uniqid()],
                        ['post_id' => $orderId, 'meta_key' => '_customer_user', 'meta_value' => $user->ID],
                        ['post_id' => $orderId, 'meta_key' => '_created_via', 'meta_value' => 'checkout'],
                        ['post_id' => $orderId, 'meta_key' => '_order_stock_reduced', 'meta_value' => 'yes'],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_index', 'meta_value' => implode(' ', $orderData['billing'])],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_index', 'meta_value' => implode(' ', $orderData['shipping'])],
                        ['post_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                    ];
                    foreach ($metaData as $meta) {
                        OrderMeta::insert($meta);
                    }
                    $totalAmount = $shippingLines[0]['total'] + $amount; // $orderData['shipping_lines'][0]['total'] + array_reduce($orderData['line_items'], function ($carry, $item) {return $carry + $item['quantity'] * $item['product_price'];}, 0);
                    $productCount = count($orderData['extra']);
                    foreach ($orderData['extra'] as $item) {
                        $orderItemId = DB::table('wp_woocommerce_order_items')->insertGetId([
                            'order_id' => $orderId,
                            'order_item_name' => $item['product_name'],
                            'order_item_type' => 'line_item'
                        ]);


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
                        Cart::where('user_id', $user->ID)
                            ->where('product_id', $item['product_id'])
                            ->where('variation_id', $item['variation_id'] ?? null)
                            ->delete();

                        $itemMeta = [
                            ['order_item_id' => $orderItemId, 'meta_key' => '_product_id', 'meta_value' => $item['product_id']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_variation_id', 'meta_value' => $item['variation_id'] ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_qty', 'meta_value' => $item['quantity']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_tax_class', 'meta_value' => $item['tax_class'] ?? ''],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal', 'meta_value' => $item['quantity'] * $item['product_price']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal_tax', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_total', 'meta_value' => $item['quantity'] * $item['product_price']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax_data', 'meta_value' => serialize(['total' => [], 'subtotal' => []])],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_total_order', 'meta_value' => $totalAmount],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_product_count', 'meta_value' => $productCount],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_priced', 'meta_value' => 'yes'],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_role', 'meta_value' => $item['wholesale_role']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_price', 'meta_value' => $item['wholesale_price']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                        ];

                        foreach ($itemMeta as $meta) {
                            OrderItemMeta::insert($meta);
                        }

                        DB::table('wp_wc_order_product_lookup')->insert([
                            'order_item_id' => $orderItemId,
                            'order_id' => $orderId,
                            'product_id' => $item['product_id'],
                            'variation_id' => $item['variation_id'] ?? 0,
                            'customer_id' => $user->ID,
                            'date_created' => now(),
                            'product_qty' => $item['quantity'],
                            'product_net_revenue' => $item['quantity'] * $item['product_price'],
                            'product_gross_revenue' => $item['quantity'] * $item['product_price'],
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
                        'payment_method' => $orderData['paymentType'],
                        'payment_method_title' => 'NMI on Card Express Transaction ID:' . $paymentResult['data']['transactionid'],
                        'transaction_id' => uniqid(),
                        'ip_address' => $ip,
                        'user_agent' => $agent,
                        'customer_note' => ''
                    ]);

                    $wp_wc_order_meta = [
                        ['order_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                        ['order_id' => $orderId, 'meta_key' => '_wwpp_order_type', 'meta_value' => $order_type],
                        ['order_id' => $orderId, 'meta_key' => '_wwpp_wholesale_order_type', 'meta_value' => $order_wholesale_role],
                        ['order_id' => $orderId, 'meta_key' => 'wwp_wholesale_role', 'meta_value' => $order_wholesale_role],
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

                    DB::table('wp_wc_orders_meta')->insert($wp_wc_order_meta);

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
                        'shipping_total' => $shippingLines[0]['total'],
                        'net_total' => $totalAmount,
                        'returning_customer' => 0,
                        'customer_id' => $user->ID,
                        'date_paid' => null,
                        'date_completed' => null,
                    ]);

                    $orderNotes = [
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'Order status changed from Pending payment to Processing (express).',
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'NMI charge complete (Charge ID: ' . $paymentResult['data']['transactionid'],
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                    ];
                    foreach ($orderNotes as $note) {
                        DB::table('wp_comments')->insert($note);
                    }

                    $checkout->delete();
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 'Order creation failed: ' . $e->getMessage()], 500);
                }

                return response()->json([
                    'status' => true,
                    'message' => 'Payment successful',
                    'data' => $paymentResult,
                    'order' => $orderId,
                    'orderNo' => $newValue
                ], 200);
            } catch (Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        } else if ($paytype == 'onaccount') {

            try {

                $checkout->update(
                    [
                        'total' => $shippingLines[0]['total'] + $amount,
                        'extra' => $lineItems,
                        'paymentType' => $paytype,
                    ]
                );
                $orderData = Checkout::where('user_id', $user->ID)->first();
                try {
                    DB::beginTransaction();
                    $options = DB::select("SELECT option_value FROM wp_options WHERE option_name= 'wt_last_order_number'");
                    $currentValue = (int)$options[0]->option_value;
                    $newValue = $currentValue + 1;
                    DB::update("UPDATE wp_options SET option_value = ? WHERE option_name = 'wt_last_order_number'", [$newValue]);
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
                        ['post_id' => $orderId, 'meta_key' => '_payment_method', 'meta_value' => $orderData['paymentType']],
                        ['post_id' => $orderId, 'meta_key' => '_payment_method_title', 'meta_value' => 'Express Onaccount Payment'], //$orderData['payment_method_title']],
                        ['post_id' => $orderId, 'meta_key' => '_transaction_id', 'meta_value' => uniqid()],
                        ['post_id' => $orderId, 'meta_key' => '_order_total', 'meta_value' => $shippingLines[0]['total'] + $amount], //$orderData['shipping_lines'][0]['total'] + array_reduce($orderData['line_items'], function ($carry, $item) {return $carry + $item['quantity'] * $item['product_price'];}, 0)],
                        ['post_id' => $orderId, 'meta_key' => '_order_currency', 'meta_value' => 'USD'],
                        ['post_id' => $orderId, 'meta_key' => '_order_key', 'meta_value' => 'wc_order_' . uniqid()],
                        ['post_id' => $orderId, 'meta_key' => '_customer_user', 'meta_value' => $user->ID],
                        ['post_id' => $orderId, 'meta_key' => '_created_via', 'meta_value' => 'checkout'],
                        ['post_id' => $orderId, 'meta_key' => '_order_stock_reduced', 'meta_value' => 'yes'],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_index', 'meta_value' => implode(' ', $orderData['billing'])],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_index', 'meta_value' => implode(' ', $orderData['shipping'])],
                        ['post_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                    ];
                    foreach ($metaData as $meta) {
                        OrderMeta::insert($meta);
                    }
                    $totalAmount = $shippingLines[0]['total'] + $amount; // $orderData['shipping_lines'][0]['total'] + array_reduce($orderData['line_items'], function ($carry, $item) {return $carry + $item['quantity'] * $item['product_price'];}, 0);
                    $productCount = count($orderData['extra']);
                    foreach ($orderData['extra'] as $item) {
                        $orderItemId = DB::table('wp_woocommerce_order_items')->insertGetId([
                            'order_id' => $orderId,
                            'order_item_name' => $item['product_name'],
                            'order_item_type' => 'line_item'
                        ]);


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
                        Cart::where('user_id', $user->ID)
                            ->where('product_id', $item['product_id'])
                            ->where('variation_id', $item['variation_id'] ?? null)
                            ->delete();

                        $itemMeta = [
                            ['order_item_id' => $orderItemId, 'meta_key' => '_product_id', 'meta_value' => $item['product_id']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_variation_id', 'meta_value' => $item['variation_id'] ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_qty', 'meta_value' => $item['quantity']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_tax_class', 'meta_value' => $item['tax_class'] ?? ''],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal', 'meta_value' => $item['quantity'] * $item['product_price']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal_tax', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_total', 'meta_value' => $item['quantity'] * $item['product_price']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax_data', 'meta_value' => serialize(['total' => [], 'subtotal' => []])],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount', 'meta_value' => 0],
                            // ['order_item_id' => $orderItemId, 'meta_key' => '_total_order', 'meta_value' => $totalAmount],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_product_count', 'meta_value' => $productCount],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_priced', 'meta_value' => 'yes'],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_role', 'meta_value' => $item['wholesale_role']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_price', 'meta_value' => $item['wholesale_price']],
                            // ['order_item_id' => $orderItemId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                        ];

                        foreach ($itemMeta as $meta) {
                            OrderItemMeta::insert($meta);
                        }

                        DB::table('wp_wc_order_product_lookup')->insert([
                            'order_item_id' => $orderItemId,
                            'order_id' => $orderId,
                            'product_id' => $item['product_id'],
                            'variation_id' => $item['variation_id'] ?? 0,
                            'customer_id' => $user->ID,
                            'date_created' => now(),
                            'product_qty' => $item['quantity'],
                            'product_net_revenue' => $item['quantity'] * $item['product_price'],
                            'product_gross_revenue' => $item['quantity'] * $item['product_price'],
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
                        'payment_method' => $orderData['paymentType'],
                        'payment_method_title' => 'Express Onaccount Payment',
                        'transaction_id' => uniqid(),
                        'ip_address' => $ip,
                        'user_agent' => $agent,
                        'customer_note' => ''
                    ]);

                    $wp_wc_order_meta = [
                        ['order_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                        ['order_id' => $orderId, 'meta_key' => '_wwpp_order_type', 'meta_value' => $order_type],
                        ['order_id' => $orderId, 'meta_key' => '_wwpp_wholesale_order_type', 'meta_value' => $order_wholesale_role],
                        ['order_id' => $orderId, 'meta_key' => 'wwp_wholesale_role', 'meta_value' => $order_wholesale_role],
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

                    DB::table('wp_wc_orders_meta')->insert($wp_wc_order_meta);

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
                        'shipping_total' => $shippingLines[0]['total'],
                        'net_total' => $totalAmount,
                        'returning_customer' => 0,
                        'customer_id' => $user->ID,
                        'date_paid' => null,
                        'date_completed' => null,
                    ]);

                    $orderNotes = [
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'Order status changed from Pending payment to Processing (express).',
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'Express Onaccount Payment',
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                    ];
                    foreach ($orderNotes as $note) {
                        DB::table('wp_comments')->insert($note);
                    }

                    $checkout->delete();
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 'Order creation failed: ' . $e->getMessage()], 500);
                }

                return response()->json([
                    'status' => true,
                    'message' => 'Payment successful',
                    'data' => 'On Account Payment',
                    'order' => $orderId,
                    'orderNo' => $newValue
                ], 200);
            } catch (Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        }

        // try {
        //     $this->validateBilling($billingInfo);
        //     $this->validateShipping($shippingInfo);
        //     $payment_token = $request->input('payment_token');

        //     // $checkout = Checkout::updateOrCreate(
        //     //     ['user_id' => $user->ID],
        //     //     [
        //     //         'isFreeze' => true,
        //     //         'total' => $amount,
        //     //         'billing' => json_encode($billingInfo),
        //     //         'shipping' => json_encode($shippingInfo),
        //     //         'extra' => json_encode(['payment_token' => $payment_token]),
        //     //     ]
        //     // );

        //     // Process the payment
        //     $saleData = $this->doSale($amount, $payment_token, $billingInfo, $shippingInfo);
        //     $paymentResult = $this->_doRequest($saleData);

        //     if (!$paymentResult['status']) {
        //         return response()->json([
        //             'status' => false,
        //             'message' => $paymentResult,
        //             'uniqueId' => null
        //         ], 200);
        //     }
        //     // $deleteCheckout=Checkout::first($checkout->id);
        //     // $deleteCheckout->delete();
        //     return response()->json([
        //         'status' => true,
        //         'message' => 'Payment successful',
        //         'data' => $paymentResult,
        //         // 'checkout_id' => $checkout->id,
        //     ], 200);
        // } catch (Exception $e) {
        //     return response()->json([
        //         'status' => false,
        //         'message' => $e->getMessage()
        //     ], 400);
        // }
    }
}
