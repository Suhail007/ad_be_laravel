<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

use GuzzleHttp\Client;

class WooCommerceController extends Controller
{
    public function addToCart(Request $request)
    {
        $username = config('woocommerce.consumer_key');
        $password = config('woocommerce.consumer_secret');
        $apiUrl = 'http://localhost/ad/wp-json/ade-woocart/v1/cart?username=utkarsh';
        $data = [
            'product_id' => $request->input('product_id'),
            'quantity' => $request->input('quantity'),
            'variation_id' => $request->input('variation_id'),
        ];

        $ch = curl_init($apiUrl);

        // Configure cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        // Execute cURL request and get the response
        $response = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            return response()->json(['error' => $error_msg], 500);
        }

        // Get the HTTP response status code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Close cURL session
        curl_close($ch);

        // Return the response
        return response()->json(json_decode($response, true), $httpCode);
    }

    public function getCart(Request $request)
    {
        $username = config('woocommerce.consumer_key');
        $password = config('woocommerce.consumer_secret');
        $apiUrl = 'http://localhost/ad/wp-json/ade-woocart/v1/cart?username=utkarsh';
        $response = Http::withBasicAuth($username, $password)->get($apiUrl);
    
        if ($response->successful()) {
            return response()->json($response->json(), $response->status());
        } else {
            return response()->json(['error' => $response->body()], $response->status());
        }
    }

    public function show($id)
    {
        // Fetch the product by ID with related data
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
            'price' => $metaData->where('key', '_price')->first()['value'] ?? '',
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
            'images' => [],  // Add logic for images if needed
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
        $variations = Product::where('post_parent', $productId)
            ->with('meta')
            ->get()
            ->map(function ($variation) {
                return [
                    'id' => $variation->ID,
                    'meta' => $variation->meta->pluck('meta_value', 'meta_key')->toArray(),
                ];
            });

        return $variations;
    }
    
}
