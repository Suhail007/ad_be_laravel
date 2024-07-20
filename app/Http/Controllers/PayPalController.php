<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Checkout;
use App\Models\ProductMeta;
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
        $this->security_key = config('services.nmi.security'); //env('NMI_SECURITY_KEY');
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
    public function cartTotal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $priceTier = $user->price_tier;
        $cartItems = Cart::where('user_id', $user->ID)->get();
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
            'username' => $user->user_login,
            'message' => 'Cart items',
            'cart_count' => count($cartData),
            'cart_items' => $cartData,
            'cart_total' => 0,
        ], 200);
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

        // Merge billing and shipping into requestOptions
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

        $billingInfo = $request->input('billing');
        $shippingInfo = $request->input('shipping');
        $amount = $request->input('amount');
        $payment_token = $request->input('payment_token');

        $lineItems = $request->input('line_items');

        $order_type = $request->input('order_type');
        $order_role = $request->input('order_role');
        $order_wholesale_role = $request->input('order_wholesale_role');

        $oncard = $request->input('oncard');


        if($oncard == 'card'){
            try {
                $this->validateBilling($billingInfo);
                $this->validateShipping($shippingInfo);
    
                $checkout = Checkout::updateOrCreate(
                    ['user_id' => $user->ID],
                    [
                        'isFreeze' => true,
                        'total' => $amount,
                        'billing' => json_encode($billingInfo),
                        'shipping' => json_encode($shippingInfo),
                        'extra' => json_encode(['line_items' => $lineItems]),
                    ]
                );
                
                $saleData = $this->doSale($amount, $payment_token, $billingInfo, $shippingInfo);
                $paymentResult = $this->_doRequest($saleData);
    
                if (!$paymentResult['status']) {
                    return response()->json([
                        'status' => false,
                        'message' => $paymentResult,
                        'uniqueId' => null
                    ], 200);
                }
    
                return response()->json([
                    'status' => true,
                    'message' => 'Payment successful',
                    'data' => $paymentResult,
                    'checkout_id' => $checkout->id,
                ], 200);
            } catch (Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        } else if($oncard == 'card') {
            try {
                $this->validateBilling($billingInfo);
                $this->validateShipping($shippingInfo);
    
                $checkout = Checkout::updateOrCreate(
                    ['user_id' => $user->ID],
                    [
                        'isFreeze' => true,
                        'total' => $amount,
                        'billing' => json_encode($billingInfo),
                        'shipping' => json_encode($shippingInfo),
                        'extra' => json_encode(['line_items' => $lineItems]),
                    ]
                );

            } catch (\Throwable $th) {
                //throw $th;
            }
        }

        // try {
        //     $this->validateBilling($billingInfo);
        //     $this->validateShipping($shippingInfo);

        //     $checkout = Checkout::updateOrCreate(
        //         ['user_id' => $user->ID],
        //         [
        //             'isFreeze' => true,
        //             'total' => $amount,
        //             'billing' => json_encode($billingInfo),
        //             'shipping' => json_encode($shippingInfo),
        //             'extra' => json_encode(['line_items' => $lineItems]),
        //         ]
        //     );
            
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

        //     return response()->json([
        //         'status' => true,
        //         'message' => 'Payment successful',
        //         'data' => $paymentResult,
        //         'checkout_id' => $checkout->id,
        //     ], 200);
        // } catch (Exception $e) {
        //     return response()->json([
        //         'status' => false,
        //         'message' => $e->getMessage()
        //     ], 400);
        // }
    }
}
