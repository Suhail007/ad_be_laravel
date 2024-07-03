<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Automattic\WooCommerce\Client;
class WooCommerceController extends Controller
{
    public function show(Request $request, $id)
    {
        // $user = JWTAuth::parseToken()->authenticate();
        // $userId = $user->ID;
        // $user = User::find($userId);
        $product = Product::with([
            'meta', 
            'categories.taxonomies', 
            'categories.children', 
            'categories.categorymeta'
        ])->findOrFail($id);
    
        // Fetch meta data
        $metaData = $product->meta->map(function ($meta) {
            return [
                'id' => $meta->meta_id,
                'key' => $meta->meta_key,
                'value' => $meta->meta_value,
            ];
        });
    
        // Fetch categories with taxonomy and meta data
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
    
        // Fetch variations for variable products (if any)
        $variations = $this->getVariations($product->ID);
    
        // Fetch product image URLs
        $thumbnailUrl = $this->getThumbnailUrl($product->ID);
        $galleryImagesUrls = $this->getGalleryImagesUrls($product->ID);
    
        // Determine the price from the product metadata
        $price = $metaData->where('key', '_price')->first()['value'] ?? '';
    
        // Construct the product response
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

    public function createNewOrder(){
        $data = [
            'payment_method' => 'bacs',
            'payment_method_title' => 'Direct Bank Transfer',
            'set_paid' => true,
            'billing' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address_1' => '969 Market',
                'address_2' => '',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postcode' => '94103',
                'country' => 'US',
                'email' => 'john.doe@example.com',
                'phone' => '(555) 555-5555'
            ],
            'shipping' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address_1' => '969 Market',
                'address_2' => '',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postcode' => '94103',
                'country' => 'US'
            ],
            'line_items' => [
                [
                    'product_id' => 22,
                    'variation_id' => 23,
                    'quantity' => 1
                ]
            ],
            'shipping_lines' => [
                [
                    'method_id' => 'flat_rate',
                    'method_title' => 'Flat Rate',
                    'total' => '10.00'
                ]
            ]
        ];
        $woocommerce=$this->woocommerce();
       $order= $woocommerce->post('orders', $data);
       return response()->json($order);

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