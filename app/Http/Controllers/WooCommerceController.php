<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Automattic\WooCommerce\Client;
use Illuminate\Support\Facades\DB;

class WooCommerceController extends Controller
{
    public function show(Request $request, $slug){
        // dd($slug);
        $product = Product::with([
            'meta', 
            'categories.taxonomies', 
            'categories.children', 
            'categories.categorymeta'
        ])->where('post_name', $slug)->firstOrFail();

        $metaData = $product->meta->map(function ($meta) {
            return [
                'id' => $meta->meta_id,
                'key' => $meta->meta_key,
                'value' => $meta->meta_value,
            ];
        });

        $categories = $product->categories->map(function ($category) {
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'taxonomy' => $category->taxonomies,
                'meta' => $category->categorymeta->pluck('meta_value', 'meta_key')->toArray(),
                'children' => $category->children,
            ];
        });
        $variations = $this->getVariations($product->ID);
        $thumbnailUrl = $this->getThumbnailUrl($product->ID);
        $galleryImagesUrls = $this->getGalleryImagesUrls($product->ID);
        $price = $metaData->where('key', '_price')->first()['value'] ?? '';
    
        $response = [
            'id' => $product->ID,
            'name' => $product->post_title,
            'slug' => $product->post_name,
            'permalink' => url('/product/' . $product->post_name),
            'date_created' => $product->post_date,
            'date_created_gmt' => $product->post_date_gmt,
            'date_modified' => $product->post_modified,
            'date_modified_gmt' => $product->post_modified_gmt,
            'type' => $product->post_type,
            'status' => $product->post_status,
            'featured' => $metaData->where('key', '_featured')->first()['value'] ?? false,
            'catalog_visibility' => $metaData->where('key', '_visibility')->first()['value'] ?? 'visible',
            'description' => $product->post_content,
            'short_description' => $product->post_excerpt,
            'sku' => $metaData->where('key', '_sku')->first()['value'] ?? '',
            'price' => $price,
            'regular_price' => $metaData->where('key', '_regular_price')->first()['value'] ?? '',
            'sale_price' => $metaData->where('key', '_sale_price')->first()['value'] ?? '',
            'date_on_sale_from' => $metaData->where('key', '_sale_price_dates_from')->first()['value'] ?? null,
            'date_on_sale_from_gmt' => $metaData->where('key', '_sale_price_dates_from_gmt')->first()['value'] ?? null,
            'date_on_sale_to' => $metaData->where('key', '_sale_price_dates_to')->first()['value'] ?? null,
            'date_on_sale_to_gmt' => $metaData->where('key', '_sale_price_dates_to_gmt')->first()['value'] ?? null,
            'on_sale' => optional($metaData->where('key', '_sale_price')->first())->value ? true : false,
            'purchasable' => $product->post_status === 'publish',
            'total_sales' => $metaData->where('key', 'total_sales')->first()['value'] ?? 0,
            'virtual' => $metaData->where('key', '_virtual')->first()['value'] ?? false,
            'downloadable' => $metaData->where('key', '_downloadable')->first()['value'] ?? false,
            'downloads' => [],  // Add logic for downloads if needed
            'download_limit' => $metaData->where('key', '_download_limit')->first()['value'] ?? -1,
            'download_expiry' => $metaData->where('key', '_download_expiry')->first()['value'] ?? -1,
            'external_url' => $metaData->where('key', '_product_url')->first()['value'] ?? '',
            'button_text' => $metaData->where('key', '_button_text')->first()['value'] ?? '',
            'tax_status' => $metaData->where('key', '_tax_status')->first()['value'] ?? 'taxable',
            'tax_class' => $metaData->where('key', '_tax_class')->first()['value'] ?? '',
            'manage_stock' => $metaData->where('key', '_manage_stock')->first()['value'] ?? false,
            'stock_quantity' => $metaData->where('key', '_stock')->first()['value'] ?? null,
            'backorders' => $metaData->where('key', '_backorders')->first()['value'] ?? 'no',
            'backorders_allowed' => $metaData->where('key', '_backorders')->first()['value'] === 'yes' ? true : false,
            'backordered' => $metaData->where('key', '_backorders')->first()['value'] === 'notify' ? true : false,
            'low_stock_amount' => $metaData->where('key', '_low_stock_amount')->first()['value'] ?? null,
            'sold_individually' => $metaData->where('key', '_sold_individually')->first()['value'] ?? false,
            'weight' => $metaData->where('key', '_weight')->first()['value'] ?? '',
            'dimensions' => [
                'length' => $metaData->where('key', '_length')->first()['value'] ?? '',
                'width' => $metaData->where('key', '_width')->first()['value'] ?? '',
                'height' => $metaData->where('key', '_height')->first()['value'] ?? ''
            ],
            'shipping_required' => $metaData->where('key', '_shipping')->first()['value'] ?? true,
            'shipping_taxable' => $metaData->where('key', '_shipping_taxable')->first()['value'] ?? true,
            'shipping_class' => $metaData->where('key', '_shipping_class')->first()['value'] ?? '',
            'shipping_class_id' => $metaData->where('key', '_shipping_class_id')->first()['value'] ?? 0,
            'reviews_allowed' => $product->comment_status === 'open',
            'average_rating' => $metaData->where('key', '_wc_average_rating')->first()['value'] ?? '0.00',
            'rating_count' => $metaData->where('key', '_wc_rating_count')->first()['value'] ?? 0,
            'upsell_ids' => [],  // Add logic for upsell_ids if needed
            'cross_sell_ids' => [],  // Add logic for cross_sell_ids if needed
            'parent_id' => $product->post_parent,
            'purchase_note' => $metaData->where('key', '_purchase_note')->first()['value'] ?? '',
            'categories' => $categories,
            'tags' => [],  // Add logic for tags if needed
            'images' => $galleryImagesUrls,  // Add gallery images URLs here
            'thumbnail_url' => $thumbnailUrl,  // Add product thumbnail URL here
            'attributes' => [],  // Add logic for attributes if needed
            'default_attributes' => [],  // Add logic for default_attributes if needed
            'variations' => $variations,
            'grouped_products' => [],  // Add logic for grouped_products if needed
            'menu_order' => $product->menu_order,
            'price_html' => '',  // Add logic for price_html if needed
            'related_ids' => [],  // Add logic for related_ids if needed
            'meta_data' => $metaData,
            'stock_status' => $metaData->where('key', '_stock_status')->first()['value'] ?? 'instock',
            'has_options' => $metaData->where('key', '_has_options')->first()['value'] ?? true,
            'post_password' => $product->post_password,
            '_links' => [
                'self' => [
                    ['href' => url('/wp-json/wc/v3/products/' . $product->ID)]
                ],
                'collection' => [
                    ['href' => url('/wp-json/wc/v3/products')]
                ]
            ],
        ];
    
        return response()->json($response);
    }
    

    private function getVariations($productId)
    {
        // Example method to fetch variations for variable products
        $variations = Product::where('post_parent', $productId)->where('post_type','product_variation')
            ->with('meta')
            ->get()
            ->map(function ($variation) {
                return [
                    'id' => $variation->ID,
                    'meta' => $variation->meta->pluck('meta_value', 'meta_key')->toArray(),
                    'thumbnail_url' => $this->getThumbnailUrl($variation->ID),  // Add variation thumbnail URL here
                    'gallery_images_urls' => $this->getGalleryImagesUrls($variation->ID),  // Add variation gallery images URLs here
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
    private function getGalleryImagesUrls($productId)
    {
        $galleryIds = ProductMeta::where('post_id', $productId)->where('meta_key', '_product_image_gallery')->value('meta_value');
        if ($galleryIds) {
            $galleryIdsArray = explode(',', $galleryIds);
            $galleryUrls = [];
    
            foreach ($galleryIdsArray as $id) {
                $url = Product::where('ID', $id)->value('guid');
                if ($url) {
                    $galleryUrls[] = str_replace('http://localhost/ad', 'https://eadn-wc05-12948169.nxedge.io', $url);
                }
            }
    
            return $galleryUrls;
        }
        return [];
    }
    

private function woocommerce(){
    $woocommerce = new Client(
        config('services.woocommerce.url'),
        config('services.woocommerce.consumer_key'),
        config('services.woocommerce.consumer_secret'),
        [
          'version' => 'wc/v3',
        ]
      );
      return $woocommerce;
}

    public function getAllOrders(){
        $woocommerce=$this->woocommerce();

        return $woocommerce->get('customers/3');
    }

    public function createNewOrder(Request $request){
        $orderData = $request->all();
        try {
            DB::beginTransaction();
            $orderId = DB::table('wp_posts')->insertGetId([
                'post_author' => 3,  
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
    
            // Insert order metadata into wp_postmeta
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
                ['post_id' => $orderId, 'meta_key' => '_customer_user', 'meta_value' => 1],  // Assuming customer ID is 1
                ['post_id' => $orderId, 'meta_key' => '_created_via', 'meta_value' => 'checkout'],
                ['post_id' => $orderId, 'meta_key' => '_order_stock_reduced', 'meta_value' => 'yes'],
                ['post_id' => $orderId, 'meta_key' => '_billing_address_index', 'meta_value' => implode(' ', $orderData['billing'])],
                ['post_id' => $orderId, 'meta_key' => '_shipping_address_index', 'meta_value' => implode(' ', $orderData['shipping'])],
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
                }
                DB::table('wp_wc_order_product_lookup')->insert([
                        'order_item_id' => $orderItemId,
                        'order_id' => $orderId,
                        'product_id' => $item['product_id'],
                        'variation_id' => $item['variation_id'] ?? 0,
                        'customer_id' => 1,  // Assuming customer ID is 1
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
                    'customer_id' => 1,  // Assuming customer ID is 1
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
    
                // Insert into wp_wc_order_addresses
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
                        'email' => $orderData['billing']['email'], // Assuming shipping email is the same as billing email
                        'phone' => $orderData['billing']['phone'] // Assuming shipping phone is the same as billing phone
                    ]
                ]);
    
                // Insert into wp_wc_order_stats
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
                    'customer_id' => 1,  // Assuming customer ID is 1
                    'date_paid' => null, // or specify a valid datetime value if necessary
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
    
            DB::commit();
            //send success mail to admin
            return response()->json(['message' => 'Order created successfully', 'order_id' => $orderId], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            //send failure mail to admin to take action
            return response()->json(['error' => 'Failed to create order: ' . $e->getMessage()], 500);
        }
    }



    public function allPaymentGate(){
        $woocommerce=$this->woocommerce();
       $data= $woocommerce->get('payment_gateways'); 
        return response()->json($data);
        
    }

    public function getPaymentMethod($method){
        $woocommerce=$this->woocommerce();
        $data = $woocommerce->get('payment_gateways/'.$method);
        return response()->json($data);
    }
    public function getShippingZone(){
        $data = [
            'name' => 'local'
        ];
        $woocommerce=$this->woocommerce();
        $data =$woocommerce->get('shipping/zones/1/locations'); //$woocommerce->post('shipping/zones', $data); // $woocommerce->get('shipping/zones');
        return response()->json($data);
    }
    public function getUAddresses(Request $request)
    {
        $user =JWTAuth::parseToken()->authenticate();
        $userId= $user->ID;
        
        $woocommerce=$this->woocommerce();
        $data = $woocommerce->get('customers/'.$userId);
        return response()->json($data);
    }

}