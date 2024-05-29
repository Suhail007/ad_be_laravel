<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomBrand;
use App\Models\CustomCategory;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function sidebar(){
    //     $slug='glass';
    //     $category = Category::where('slug', $slug)
    //     ->with([
    //         'taxonomy' => function ($query) {
    //             $query->select('term_taxonomy_id', 'term_id', 'parent', 'count');
    //         },
    //         'categorymeta' => function ($query) {
    //             $query->select('meta_id', 'term_id', 'meta_key', 'meta_value')
    //                   ->where('meta_key', 'visibility');
    //         },
    //         'taxonomy.childTerms.term' => function ($query) {
    //             $query->select('term_id', 'name', 'slug')
    //                   ->with([
    //                       'categorymeta' => function ($query) {
    //                           $query->select('meta_id', 'term_id', 'meta_key', 'meta_value')
    //                                 ->where('meta_key', 'visibility');
    //                       }
    //                   ]);
    //         }
    //     ])
    //     ->select('term_id', 'name', 'slug')
    //     ->first();

    // // Check if category exists
    // if (!$category) {
    //     return response()->json(['message' => 'Category not found'], 404);
    // }
    $category = CustomCategory::get();
    $brand= CustomBrand::where('category','!=','')->get();
    return response()->json(['category'=>$category,'brands'=>$brand]);
    }
    
    public function categoryProduct(string $slug){
        $searchTerm = $slug;
        $perPage = 20;

        $products = Product::with([
            'meta' => function($query) {
                $query->select('post_id', 'meta_key', 'meta_value')
                      ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
            },
            'categories' => function($query) {
                $query->select('wp_terms.term_id', 'wp_terms.name')
                      ->with([
                          'categorymeta' => function($query) {
                              $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                          }
                      ]);
            }
        ])
        ->select('ID', 'post_title', 'post_modified', 'post_name')
        ->where('post_type', 'product')
        ->where(function($query) use ($searchTerm) {
            $query //->where('post_title', 'LIKE', '%' . $searchTerm . '%')
                //   ->orWhereHas('meta', function($query) use ($searchTerm) {
                //       $query->where('meta_key', '_sku')
                //             ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
                //   })
                  ->orWhereHas('categories', function($query) use ($searchTerm) {
                      $query->where('slug', '=',$searchTerm);
                  })
                  ;
        })
        ->orderBy('post_modified', 'desc')
        ->paginate($perPage);
        
        $products->getCollection()->transform(function($product) {
            return [
                'ID' => $product->ID,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'thumbnail_url' => $product->thumbnail_url,
                'categories' => $product->categories->map(function($category) {
                    $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                    return [
                        'term_id' => $category->term_id,
                        'name' => $category->name,
                        'visibility' => $visibility ? $visibility : 'N/A',
                    ];
                }),
                'meta' => $product->meta->map(function($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                }),
                'post_modified' => $product->post_modified
            ];
        });

        return response()->json($products);
    }

    public function searchProducts(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = 20;

        $products = Product::with([
            'meta' => function($query) {
                $query->select('post_id', 'meta_key', 'meta_value')
                      ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
            },
            'categories' => function($query) {
                $query->select('wp_terms.term_id', 'wp_terms.name')
                      ->with([
                          'categorymeta' => function($query) {
                              $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                          }
                      ]);
            }
        ])
        ->select('ID', 'post_title', 'post_modified', 'post_name')
        ->where('post_type', 'product')
        ->where(function($query) use ($searchTerm) {
            $query->where('post_title', 'LIKE', '%' . $searchTerm . '%')
                //   ->orWhereHas('meta', function($query) use ($searchTerm) {
                //       $query->where('meta_key', '_sku')
                //             ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
                //   })
                //   ->orWhereHas('categories', function($query) use ($searchTerm) {
                //       $query->where('name', 'LIKE', '%' . $searchTerm . '%');
                //   })
                  ;
        })
        ->orderBy('post_modified', 'desc')
        ->paginate($perPage);
        
        $products->getCollection()->transform(function($product) {
            return [
                'ID' => $product->ID,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'thumbnail_url' => $product->thumbnail_url,
                'categories' => $product->categories->map(function($category) {
                    $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                    return [
                        'term_id' => $category->term_id,
                        'name' => $category->name,
                        'visibility' => $visibility ? $visibility : 'N/A',
                    ];
                }),
                'meta' => $product->meta->map(function($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                }),
                'post_modified' => $product->post_modified
            ];
        });

        return response()->json($products);
    }
    public function searchProductsBySKU(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = 20;

        $products = Product::with([
            'meta' => function($query) {
                $query->select('post_id', 'meta_key', 'meta_value')
                      ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
            },
            'categories' => function($query) {
                $query->select('wp_terms.term_id', 'wp_terms.name')
                      ->with([
                          'categorymeta' => function($query) {
                              $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                          }
                      ]);
            }
        ])
        ->select('ID', 'post_title', 'post_modified', 'post_name')
        ->where('post_type', 'product')
        ->where(function($query) use ($searchTerm) {
            $query //->where('post_title', 'LIKE', '%' . $searchTerm . '%')
                  ->whereHas('meta', function($query) use ($searchTerm) {
                      $query->where('meta_key', '_sku')
                            ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
                  })
                //   ->orWhereHas('categories', function($query) use ($searchTerm) {
                //       $query->where('name', 'LIKE', '%' . $searchTerm . '%');
                //   })
                  ;
        })
        ->orderBy('post_modified', 'desc')
        ->paginate($perPage);
        
        $products->getCollection()->transform(function($product) {
            return [
                'ID' => $product->ID,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'thumbnail_url' => $product->thumbnail_url,
                'categories' => $product->categories->map(function($category) {
                    $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                    return [
                        'term_id' => $category->term_id,
                        'name' => $category->name,
                        'visibility' => $visibility ? $visibility : 'N/A',
                    ];
                }),
                'meta' => $product->meta->map(function($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                }),
                'post_modified' => $product->post_modified
            ];
        });

        return response()->json($products);
    }
    public function searchProductsByCAT(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = 20;

        $products = Product::with([
            'meta' => function($query) {
                $query->select('post_id', 'meta_key', 'meta_value')
                      ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
            },
            'categories' => function($query) {
                $query->select('wp_terms.term_id', 'wp_terms.name')
                      ->with([
                          'categorymeta' => function($query) {
                              $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                          }
                      ]);
            }
        ])
        ->select('ID', 'post_title', 'post_modified', 'post_name')
        ->where('post_type', 'product')
        ->where(function($query) use ($searchTerm) {
            $query //->where('post_title', 'LIKE', '%' . $searchTerm . '%')
                //   ->orWhereHas('meta', function($query) use ($searchTerm) {
                //       $query->where('meta_key', '_sku')
                //             ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
                //   })
                  ->whereHas('categories', function($query) use ($searchTerm) {
                      $query->where('name', 'LIKE', '%' . $searchTerm . '%');
                  })
                  ;
        })
        ->orderBy('post_modified', 'desc')
        ->paginate($perPage);
        
        $products->getCollection()->transform(function($product) {
            return [
                'ID' => $product->ID,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'thumbnail_url' => $product->thumbnail_url,
                'categories' => $product->categories->map(function($category) {
                    $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                    return [
                        'term_id' => $category->term_id,
                        'name' => $category->name,
                        'visibility' => $visibility ? $visibility : 'N/A',
                    ];
                }),
                'meta' => $product->meta->map(function($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                }),
                'post_modified' => $product->post_modified
            ];
        });

        return response()->json($products);
    }
    public function searchProductsAll(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = 20;

        $products = Product::with([
            'meta' => function($query) {
                $query->select('post_id', 'meta_key', 'meta_value')
                      ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
            },
            'categories' => function($query) {
                $query->select('wp_terms.term_id', 'wp_terms.name')
                      ->with([
                          'categorymeta' => function($query) {
                              $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                          }
                      ]);
            }
        ])
        ->select('ID', 'post_title', 'post_modified', 'post_name')
        ->where('post_type', 'product')
        ->where(function($query) use ($searchTerm) {
            $query->where('post_title', 'LIKE', '%' . $searchTerm . '%')
                  ->orWhereHas('meta', function($query) use ($searchTerm) {
                      $query->where('meta_key', '_sku')
                            ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
                  })
                  ->orWhereHas('categories', function($query) use ($searchTerm) {
                      $query->where('name', 'LIKE', '%' . $searchTerm . '%');
                  })
                  ;
        })
        ->orderBy('post_modified', 'desc')
        ->paginate($perPage);
        
        $products->getCollection()->transform(function($product) {
            return [
                'ID' => $product->ID,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'thumbnail_url' => $product->thumbnail_url,
                'categories' => $product->categories->map(function($category) {
                    $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                    return [
                        'term_id' => $category->term_id,
                        'name' => $category->name,
                        'visibility' => $visibility ? $visibility : 'N/A',
                    ];
                }),
                'meta' => $product->meta->map(function($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                }),
                'post_modified' => $product->post_modified
            ];
        });

        return response()->json($products);
    }

    // public function categoryProducts(Request $request){
    //     $searchTerm = $request->input('searchTerm', '');
    //     $perPage = $request->input('perPage', 20);
    //     $products = Product::with([
    //         'meta' => function($query) {
    //             $query->select('post_id', 'meta_key', 'meta_value')
    //                   ->whereIn('meta_key', ['_price', '_stock_status', '_sku']);
    //         },
    //         'categories' => function($query) {
    //             $query->select('wp_terms.term_id', 'wp_terms.name');
    //         }
    //     ])
    //     ->select('ID', 'post_title', 'post_modified')
    //     ->where('post_type', 'product')
    //     ->where(function($query) use ($searchTerm) {
    //         $query //->where('post_title', 'LIKE', '%' . $searchTerm . '%')
    //             //   ->orWhereHas('meta', function($query) use ($searchTerm) {
    //             //       $query->where('meta_key', '_sku')
    //             //             ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
    //             //   })
    //               ->whereHas('categories', function($query) use ($searchTerm) {
    //                   $query->where('slug', 'LIKE', '%' . $searchTerm . '%');
    //               })
    //               ;
    //     })
    //     ->orderBy('post_modified', 'desc')
    //     ->paginate($perPage);
    //     return response()->json($products);
    // }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
