<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomBrand;
use App\Models\CustomCategory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Automattic\WooCommerce\Client;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    protected $woocommerce;

    public function __construct(Client $woocommerce)
    {
        $this->woocommerce = $woocommerce;
    }

    public function shos($id)
    {
        try {
            $product = $this->woocommerce->get("products/{$id}");
            return response()->json($product);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Product not found'], 404);
        }
    }
    public function showProduct($id)
    {
        $product = Product::with([
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
        ])->find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $productData = [
            'ID' => $product->ID,
            'title' => $product->post_title,
            'slug' => $product->post_name,
            'thumbnail_url' => $this->getThumbnailUrl($product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first()),
            'sku' => $product->meta->where('meta_key', '_sku')->pluck('meta_value')->first(),
            'price' => $product->meta->where('meta_key', '_price')->pluck('meta_value')->first(),
            'description' => $product->post_content,
        ];

        return response()->json($productData);
    }
    private function getThumbnailUrl($thumbnailId)
    {
        if (!$thumbnailId) {
            return null;
        }
        $attachment = DB::table('wp_posts')->where('ID', $thumbnailId)->first();
        if ($attachment) {
            return $attachment->guid;
        }
        return null;
    }
    public function sidebar()
    {
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
        $brand = CustomBrand::where('category', '!=', '')->get();
        $response = response()->json(['category' => $category, 'brands' => $brand]);
        $response->header('Cache-Control', 'public, max-age=600');
        return $response;
    }
    public function categoryProduct(Request $request, string $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);

        $auth = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';

            if ($user->ID) {
                $auth = true;
                if ($slug == 'new-arrivals') {
                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
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
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->whereHas('meta', function ($query) {
                            $query->where('meta_key', '_stock_status')
                                ->where('meta_value', 'instock');
                        })
                        ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                            $query->where('slug', $slug)
                                ->where('taxonomy', 'product_cat');
                        });
                    switch ($sortBy) {
                        case 'popul':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                            break;

                        case 'plh':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                            break;

                        case 'phl':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                            break;

                        default:
                            $products->orderBy('post_date', 'desc');
                            break;
                    }

                    $products = $products->paginate($perPage, ['*'], 'page', $page);
                } else {

                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
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
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->whereHas('meta', function ($query) {
                            $query->where('meta_key', '_stock_status')
                                ->where('meta_value', 'instock');
                        })
                        ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                            $query->where('slug', $slug)
                                ->where('taxonomy', 'product_cat');
                        });
                    switch ($sortBy) {
                        case 'popul':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                            break;

                        case 'plh':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                            break;

                        case 'phl':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                            break;

                        default:
                            $products->orderBy('post_date', 'desc');
                            break;
                    }

                    $products = $products->paginate($perPage, ['*'], 'page', $page);
                }
            }
        } catch (\Throwable $th) {
            $priceTier = '';
            if ($slug == 'new-arrivals') {
                $products = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
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
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                        $query->where('slug', $slug)
                            ->where('taxonomy', 'product_cat');
                    })
                    ->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }

                $products = $products->paginate($perPage, ['*'], 'page', $page);
            } else {
                // $products = Product::with([
                //     'meta' => function ($query) {
                //         $query->select('post_id', 'meta_key', 'meta_value')
                //             ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                //     },
                //     'categories' => function ($query) {
                //         $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                //             ->with([
                //                 'categorymeta' => function ($query) {
                //                     $query->select('term_id', 'meta_key', 'meta_value')
                //                         ->where('meta_key', 'visibility');
                //                 },
                //                 'taxonomies' => function ($query) {
                //                     $query->select('term_id', 'taxonomy')->where('product_visibility',);
                //                 }
                //             ]);
                //     }
                // ])
                //     ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                //     ->where('post_type', 'product')
                //     ->whereHas('meta', function ($query) {
                //         $query->where('meta_key', '_stock_status')
                //             ->where('meta_value', 'instock');
                //     })
                //     ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                //         $query->where('slug', $slug)
                //             ->where('taxonomy', 'product_cat');
                //     })
                //     ->orderBy('post_date', 'desc')
                //     ->paginate($perPage, ['*'], 'page', $page);



                $products = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
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
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                        $query->where('slug', $slug)
                            ->where('taxonomy', 'product_cat');
                    })
                    ->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }

                $products = $products->paginate($perPage, ['*'], 'page', $page);
            }
        }

        try {
            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                if (!$auth) {
                    $ad_price = null;
                } else {
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                }
                $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                $metaArray = $product->meta->map(function ($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                })->toArray(); // Ensure metaArray is a plain array

                // Filter meta based on authentication status
                $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                    return $meta['meta_key'] !== '_price';
                }));

                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy = $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $filteredMeta,
                    'post_modified' => $product->post_modified
                ];
            });
        } catch (\Throwable $th) {
            return response()->json($th);
        }

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }

        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }
    public function brandProducts(Request $request, string $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);

        $auth = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user->ID) {
                $auth = true;
                $products = Product::with([
                    'meta' => function ($query) use ($priceTier) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
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
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                        $query->where('slug', $slug)
                            ->where('taxonomy', 'product_brand');
                    });
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                CAST((SELECT meta_value FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = 'total_sales' 
                                      LIMIT 1) AS UNSIGNED) DESC
                            ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                            ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                            ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }

                $products = $products->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
            $priceTier = '';
            $products = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
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
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                    $query->where('slug', $slug)
                        ->where('taxonomy', 'product_brand');
                })
                ->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                });
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                CAST((SELECT meta_value FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = 'total_sales' 
                                      LIMIT 1) AS UNSIGNED) DESC
                            ");
                    break;

                case 'plh':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                            ");
                    break;

                case 'phl':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                            ");
                    break;

                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }

            $products = $products->paginate($perPage, ['*'], 'page', $page);
        }

        $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
            $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
            if (!$auth) {
                $ad_price = null;
            } else {
                try {
                    $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                    if ($ad_price == '') {
                        $ad_price = $this->getVariations($product->ID, $priceTier);
                        $ad_price = $ad_price[0];
                    }
                } catch (\Throwable $th) {
                    $ad_price = null;
                }
            }
            $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

            $metaArray = $product->meta->map(function ($meta) {
                return [
                    'meta_key' => $meta->meta_key,
                    'meta_value' => $meta->meta_value
                ];
            })->toArray(); // Ensure metaArray is a plain array

            // Filter meta based on authentication status
            $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                return $meta['meta_key'] !== '_price';
            }));

            return [
                'ID' => $product->ID,
                'ad_price' => $ad_price,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'thumbnail_url' => $thumbnailUrl,
                'categories' => $product->categories->map(function ($category) {
                    $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                    $taxonomy =  $category->taxonomies->taxonomy;
                    return [
                        'term_id' => $category->term_id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'visibility' => $visibility ? $visibility : 'public',
                        'taxonomy' => $taxonomy ? $taxonomy : 'public',
                    ];
                }),
                'meta' => $filteredMeta,
                'post_modified' => $product->post_modified
            ];
        });

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }
        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }

    public function searchProducts(Request $request)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);
        $searchTerm = $request->input('searchTerm', '');
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                $query = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
                    },
                    'categories' => function ($query) {
                        $query->select('wp_terms.term_id', 'wp_terms.name')
                            ->with([
                                'categorymeta' => function ($query) {
                                    $query->select('term_id', 'meta_key', 'meta_value')
                                        ->where('meta_key', 'visibility');
                                }
                            ]);
                    }
                ])
                    ->select('ID', 'post_title', 'post_modified', 'post_name')
                    ->where('post_type', 'product');
            }
        } catch (\Throwable $th) {
            $query = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            }
                        ]);
                }
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product');
        }


        if (!empty($searchTerm)) {
            $searchWords = preg_split('/\s+/', $searchTerm);
            $regexPattern = implode('.*', array_map(function ($word) {
                return "(?=.*" . preg_quote($word) . ")";
            }, $searchWords));

            $query->where(function ($query) use ($regexPattern) {
                $query->where('post_title', 'REGEXP', $regexPattern)
                    ->orWhere('post_name', 'REGEXP', $regexPattern);
            });
        }
        $products = $query->orderBy('post_date', 'desc')->paginate($perPage, ['*'], 'page', $page);

        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user) {
                $products->getCollection()->transform(function ($product) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

                    return [
                        'ID' => $product->ID,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'visibility' => $visibility ? $visibility : 'public',
                            ];
                        }),
                        'meta' => $product->meta->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        }),
                        'post_modified' => $product->post_modified
                    ];
                });
            }
            return response()->json(['status' => 'auth', 'user' => $user, 'products' => $products]);
        } catch (\Throwable $th) {
            try {
                $originalCollection = $products->getCollection();

                $filteredCollection = $originalCollection->filter(function ($product) {
                    $hasProtectedCategory = $product->categories->contains(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        return $visibility === 'protected';
                    });
                    return !$hasProtectedCategory;
                });

                $transformedCollection = $filteredCollection->transform(function ($product) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

                    return [
                        'ID' => $product->ID,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'visibility' => $visibility ? $visibility : 'public',
                            ];
                        }),
                        'meta' => $product->meta->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        }),
                        'post_modified' => $product->post_modified
                    ];
                });

                $products->setCollection($transformedCollection->values());

                return response()->json(['status' => 'no-auth', 'products' => $products]);
            } catch (\Throwable $th) {
                return response()->json(['status' => 'no-auth', 'message' => $th->getMessage()], 500);
            }
        }
    }

    public function searchProductsAll(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);

        $auth = false;

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            $auth = true;

            $query = Product::with([
                'meta' => function ($query) use ($priceTier) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name')
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
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')->where('post_status', 'publish')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                });

            if (!empty($searchTerm)) {
                $searchWords = preg_split('/\s+/', $searchTerm);
                $regexPattern = implode('.*', array_map(function ($word) {
                    return "(?=.*" . preg_quote($word) . ")";
                }, $searchWords));

                $query->where(function ($query) use ($regexPattern) {
                    $query->where('post_title', 'REGEXP', $regexPattern)
                        ->orWhereHas('meta', function ($query) use ($regexPattern) {
                            $query->where('meta_key', '_sku')
                                ->where('meta_value', 'REGEXP', $regexPattern);
                        });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $query->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                    CAST((SELECT meta_value FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = 'total_sales' 
                          LIMIT 1) AS UNSIGNED) DESC
                ");
                    break;

                case 'plh':
                    $query->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                ");
                    break;

                case 'phl':
                    $query->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                ");
                    break;

                default:
                    $query->orderBy('post_date', 'desc');
                    break;
            }

            $products = $query->paginate($perPage, ['*'], 'page', $page);
        } catch (\Throwable $th) {
            $priceTier = '';

            $query = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name')
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
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                })
                ->where('post_type', 'product')->where('post_status', 'publish');

            if (!empty($searchTerm)) {
                $searchWords = preg_split('/\s+/', $searchTerm);
                $regexPattern = implode('.*', array_map(function ($word) {
                    return "(?=.*" . preg_quote($word) . ")";
                }, $searchWords));

                $query->where(function ($query) use ($regexPattern) {
                    $query->where('post_title', 'REGEXP', $regexPattern)
                        ->orWhereHas('meta', function ($query) use ($regexPattern) {
                            $query->where('meta_key', '_sku')
                                ->where('meta_value', 'REGEXP', $regexPattern);
                        });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $query->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                    CAST((SELECT meta_value FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = 'total_sales' 
                          LIMIT 1) AS UNSIGNED) DESC
                ");
                    break;

                case 'plh':
                    $query->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                ");
                    break;

                case 'phl':
                    $query->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                ");
                    break;

                default:
                    $query->orderBy('post_date', 'desc');
                    break;
            }

            $products = $query->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user) {
                $products->getCollection()->transform(function ($product) use ($priceTier) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                    return [
                        'ID' => $product->ID,
                        'ad_price' => $ad_price,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            $taxonomy = $category->taxonomies->taxonomy;
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'slug' => $category->slug,
                                'visibility' => $visibility ? $visibility : 'public',
                                'taxonomy' => $taxonomy ? $taxonomy : 'public',
                            ];
                        }),
                        'meta' => $product->meta->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        }),
                        'post_modified' => $product->post_modified
                    ];
                });

                //cache
                // $userId = $user->ID;
                // $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
                // $etag = md5($userId . implode(',', $productModifiedTimestamps));
                // if ($request->header('If-None-Match') === $etag) {
                //     return response()->json(['status' => 'auth', 'user' => $user, 'products' => $products], Response::HTTP_NOT_MODIFIED);
                // }
                // $response = response()->json(['status' => 'auth', 'user' => $user, 'products' => $products]);
                // $response->header('ETag', $etag);

                // if ($auth) {
                //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
                //     // $response->header('Cache-Control', 'public, max-age=300');
                // }
                // return $response;
                return response()->json(['status' => 'auth', 'user' => $user, 'products' => $products]);
            }
        } catch (\Throwable $th) {
            Log::error('Error processing authenticated request: ' . $th->getMessage());

            try {
                $originalCollection = $products->getCollection();

                $filteredCollection = $originalCollection->filter(function ($product) {
                    $hasProtectedCategory = $product->categories->contains(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        return $visibility === 'protected';
                    });
                    return !$hasProtectedCategory;
                });

                $transformedCollection = $filteredCollection->transform(function ($product) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

                    return [
                        'ID' => $product->ID,
                        'ad_price' => null,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'visibility' => $visibility ? $visibility : 'public',
                            ];
                        }),
                        'meta' =>  $product->meta->filter(function ($meta) {
                            return $meta->meta_key !== '_price';
                        })->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        })->values(),
                        'post_modified' => $product->post_modified
                    ];
                });

                $products->setCollection($transformedCollection->values());

                //cache
                // $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
                // if ($request->header('If-None-Match') === $etag) {
                //     return response()->json(['status' => 'no-auth', 'products' => $products], Response::HTTP_NOT_MODIFIED);
                // }
                // $response = response()->json(['status' => 'no-auth', 'products' => $products]);
                // $response->header('ETag', $etag);

                // if ($auth) {
                //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
                //     // $response->header('Cache-Control', 'public, max-age=300');
                // }
                // return $response;

                return response()->json(['status' => 'no-auth', 'products' => $products]);
            } catch (\Throwable $th) {
                Log::error('Error processing unauthenticated request: ' . $th->getMessage());
                return response()->json(['status' => 'no-auth', 'message' => $th->getMessage()], 500);
            }
        }
    }

    public function getRelatedProducts($id)
    {
        // Fetch the product
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $subcatIds = $product->categories()
            ->whereHas('taxonomies', function ($query) {
                $query->where('parent', '!=', 0);
            })
            ->pluck('term_id');

        if ($subcatIds->isEmpty()) {
            return response()->json(['error' => 'No subcategories found for this product'], 404);
        }

        $relatedProducts = Product::whereHas('categories', function ($query) use ($subcatIds) {
            $query->whereIn('term_taxonomy_id', $subcatIds);
        })->orderBy('post_date', 'desc')->take(20)->get();

        if ($relatedProducts->isEmpty()) {
            return response()->json(['error' => 'No related products found'], 404);
        }

        $relatedProductsData = $relatedProducts->map(function ($relatedProduct) {
            $categoryVisibility = $relatedProduct->categories->map(function ($category) {
                return $category->visibility;
            })->toArray();

            return [
                'ID' => $relatedProduct->ID,
                'name' => $relatedProduct->post_title,
                'slug' => $relatedProduct->post_name,
                'thumbnail' => $relatedProduct->thumbnail_url,
                'product_visibility' => $relatedProduct->visibility,
                'date' => $relatedProduct->post_modified_gmt,
            ];
        });

        return response()->json(['related_products' => $relatedProductsData], 200);
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
                $pattern = '/^(_sku|attribute_.*|_stock|_regular_price|_price|_stock_status' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';

                // Filter meta data to include only the selected fields
                $filteredMetaData = array_filter($metaData, function ($key) use ($pattern) {
                    return preg_match($pattern, $key);
                }, ARRAY_FILTER_USE_KEY);
                $adPrice = $metaData[$priceTier] ?? $metaData['_price'] ?? $metaData['_regular_price'] ?? null;

                return $adPrice;
            });

        return $variations;
    }
    public function productList(Request $request)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $auth = false;
        $productIDArray = $request->input('productIDs', []);
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user->ID) {
                $auth = true;
                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
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
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->whereIn('ID',$productIDArray)
                        ->whereHas('meta', function ($query) {
                            $query->where('meta_key', '_stock_status')
                                ->where('meta_value', 'instock');
                        });
                    switch ($sortBy) {
                        case 'popul':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                            break;

                        case 'plh':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                            break;

                        case 'phl':
                            $products->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                            break;

                        default:
                            $products->orderBy('post_date', 'desc');
                            break;
                    }
                    $products = $products->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
            $priceTier = '';
                $products = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
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
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->whereIn('ID',$productIDArray)
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }
                $products = $products->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                if (!$auth) {
                    $ad_price = null;
                } else {
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                }
                $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                $metaArray = $product->meta->map(function ($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                })->toArray(); // Ensure metaArray is a plain array

                // Filter meta based on authentication status
                $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                    return $meta['meta_key'] !== '_price';
                }));

                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy = $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $filteredMeta,
                    'post_modified' => $product->post_modified
                ];
            });
        } catch (\Throwable $th) {
            return response()->json($th);
        }

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }

        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }
    public function combineProducts(Request $request, string $slug=null)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $searchTerm = $request->input('searchTerm', '');
        $auth = false;
        $catIDArray = $request->input('catIDs', []);
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user->ID) {
                $auth = true;
                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
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
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->orWhereHas('categories.taxonomies', function ($query) use ($catIDArray) {
                            $query->whereIn('term_id', $catIDArray);
                        });
                        $searchProducts = Product::with([
                            'meta' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                            },
                            'categories' => function ($query) {
                                $query->select('wp_terms.term_id', 'wp_terms.name')
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
                            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                            ->where('post_type', 'product')->where('post_status', 'publish');
            
                        if (!empty($searchTerm)) {
                            $searchWords = preg_split('/\s+/', $searchTerm);
                            $regexPattern = implode('.*', array_map(function ($word) {
                                return "(?=.*" . preg_quote($word) . ")";
                            }, $searchWords));
            
                            $searchProducts->where(function ($query) use ($regexPattern) {
                                $query->where('post_title', 'REGEXP', $regexPattern)
                                    ->orWhereHas('meta', function ($query) use ($regexPattern) {
                                        $query->where('meta_key', '_sku')
                                            ->where('meta_value', 'REGEXP', $regexPattern);
                                    });
                            });
                        }
                        $products = $products->get();
                        $searchProducts = $searchProducts->get();
                
                        $combinedProducts = $products->merge($searchProducts)->unique('ID');
                    switch ($sortBy) {
                        case 'popul':
                            $combinedProducts->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                            break;

                        case 'plh':
                            $combinedProducts->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                            break;

                        case 'phl':
                            $combinedProducts->with(['meta' => function ($query) {
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                            break;

                        default:
                            $combinedProducts->orderBy('post_date', 'desc');
                            break;
                    }
                    $products = $combinedProducts->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
            $priceTier = '';
                $products = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
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
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }
                $products = $products->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                if (!$auth) {
                    $ad_price = null;
                } else {
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                }
                $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                $metaArray = $product->meta->map(function ($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                })->toArray(); // Ensure metaArray is a plain array

                // Filter meta based on authentication status
                $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                    return $meta['meta_key'] !== '_price';
                }));

                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy = $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $filteredMeta,
                    'post_modified' => $product->post_modified
                ];
            });
        } catch (\Throwable $th) {
            return response()->json($th);
        }

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }

        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }
    
}
