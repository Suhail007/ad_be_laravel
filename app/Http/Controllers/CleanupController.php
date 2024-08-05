<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CleanupController extends Controller
{
    public function menuCleanUp()
    {

        $brandUrls = DB::table('wp_custom_value_save')->pluck('brand_url');

        // Step 2: Process each brand URL
        foreach ($brandUrls as $brandUrl) {

            $slug = trim(parse_url($brandUrl, PHP_URL_PATH), 'brand/');

            // Check if there are products associated with this slug
            $hasProducts = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                }
                ])
                ->select('ID', 'post_title', 'post_modified', 'post_name')
                ->where('post_type', 'product')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                    $query->where('slug', $slug)
                        ->where('taxonomy', 'product_brand');
                })
                ->exists(); // Check if any products exist

            if (!$hasProducts) {
                DB::table('wp_custom_value_save')
                    ->where('brand_url', $brandUrl)
                    ->delete();
            }
        }
        return true;
    }
}
