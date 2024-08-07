<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMeta;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function show(Request $request, $slug)
    {
       
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

        
        $priceTier ='';
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
            'description' => $product->post_content,
            'short_description' => $product->post_excerpt,
            'sku' => $metaData->where('key', '_sku')->first()['value'] ?? '',
            'ad_price' => $wholesalePrice = ProductMeta::where('post_id', $product->ID)->where('meta_key', $priceTier)->value('meta_value') ?? $metaData->where('key', '_price')->first()['value']  ?? $metaData->where('key', '_regular_price')->first()['value'] ?? $variations->ad_price ?? null,
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
                $pattern = '/^(_sku|attribute_.*|_stock|_regular_price|_price|_stock_status|max_quantity|min_quantity' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';

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
