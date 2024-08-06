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
        $response->header('Cache-Control', 'public, max-age=3600');
        return $response;
    }
    public function categoryProduct(Request $request, string $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);

        // $orderBy = 'latest';
        // if($orderBy == "min"){
        //     $orderBy = "low to high";
        // }
        // if($orderBy == "max"){
        //     $orderBy = "low to high";
        // }
        // if($orderBy == "popular"){
        //     $orderBy = "popular";
        // }


        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                if ($slug == 'new-arrivals') {
                    $products = Product::with([
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
                        ->orderBy('post_date', 'desc')
                        ->paginate($perPage, ['*'], 'page', $page);
                } else {
                    $products = Product::with([
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
                        ->orderBy('post_date', 'desc')
                        ->paginate($perPage, ['*'], 'page', $page);
                }
            }
        } catch (\Throwable $th) {
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
                    ->orderBy('post_date', 'desc')
                    ->paginate($perPage, ['*'], 'page', $page);
            } else {
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
                    ->orderBy('post_date', 'desc')
                    ->paginate($perPage, ['*'], 'page', $page);
            }
        }


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

        return response()->json($products);
    }
    public function brandProducts(Request $request, string $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                $products = Product::with([
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
                    ->orderBy('post_date', 'desc')
                    ->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
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
                ->orderBy('post_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        }

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
                    $taxonomy =  $category->taxonomies->taxonomy;
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

        $cacheKey = 'products_' . md5($searchTerm . $perPage . $sortBy . $page);
        $cacheDuration = 10080;

        $products = Cache::remember($cacheKey, $cacheDuration, function () use ($searchTerm, $perPage, $page) {
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
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product');

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

            return $query->orderBy('post_date', 'desc')->paginate($perPage, ['*'], 'page', $page);
        });

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

                return response()->json(['status' => 'auth', 'user' => $user, 'products' => $products]);
            }
        } catch (\Throwable $th) {
            Log::error('Error processing authenticated request: ' . $th->getMessage());

            // Filter and transform the products if the user is not authenticated
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

    // public function searchProductsBySKU(Request $request)
    // {
    //     $searchTerm = $request->input('searchTerm', '');
    //     $perPage = 20;
    //     $products = Product::with([
    //         'meta' => function ($query) {
    //             $query->select('post_id', 'meta_key', 'meta_value')
    //                 ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
    //         },
    //         'categories' => function ($query) {
    //             $query->select('wp_terms.term_id', 'wp_terms.name')
    //                 ->with([
    //                     'categorymeta' => function ($query) {
    //                         $query->select('term_id', 'meta_key', 'meta_value')
    //                             ->where('meta_key', 'visibility');
    //                     }
    //                 ]);
    //         }
    //     ])
    //         ->select('ID', 'post_title', 'post_modified', 'post_name')
    //         ->where('post_type', 'product')
    //         ->where(function ($query) use ($searchTerm) {
    //             $query->whereHas('meta', function ($query) use ($searchTerm) {
    //                 $query->where('meta_key', '_sku')->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
    //             });
    //         })
    //         ->orderBy('post_modified', 'desc')
    //         ->paginate($perPage);

    //     $products->getCollection()->transform(function ($product) {
    //         $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
    //         $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

    //         return [
    //             'ID' => $product->ID,
    //             'title' => $product->post_title,
    //             'slug' => $product->post_name,
    //             'thumbnail_url' => $thumbnailUrl,
    //             'categories' => $product->categories->map(function ($category) {
    //                 $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
    //                 return [
    //                     'term_id' => $category->term_id,
    //                     'name' => $category->name,
    //                     'visibility' => $visibility ? $visibility : 'public',
    //                 ];
    //             }),
    //             'meta' => $product->meta->map(function ($meta) {
    //                 return [
    //                     'meta_key' => $meta->meta_key,
    //                     'meta_value' => $meta->meta_value
    //                 ];
    //             }),
    //             'post_modified' => $product->post_modified
    //         ];
    //     });

    //     return response()->json($products);
    // }
    // public function searchProductsByCAT(Request $request)
    // {
    //     $searchTerm = $request->input('searchTerm', '');
    //     $perPage = 20;

    //     $products = Product::with([
    //         'meta' => function ($query) {
    //             $query->select('post_id', 'meta_key', 'meta_value')
    //                 ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
    //         },
    //         'categories' => function ($query) {
    //             $query->select('wp_terms.term_id', 'wp_terms.name')
    //                 ->with([
    //                     'categorymeta' => function ($query) {
    //                         $query->select('term_id', 'meta_key', 'meta_value')
    //                             ->where('meta_key', 'visibility');
    //                     }
    //                 ]);
    //         }
    //     ])
    //         ->select('ID', 'post_title', 'post_modified', 'post_name')
    //         ->where('post_type', 'product')
    //         ->where(function ($query) use ($searchTerm) {
    //             $query //->where('post_title', 'LIKE', '%' . $searchTerm . '%')
    //                 // ->orWhereHas('meta', function ($query) use ($searchTerm) {
    //                 //     $query->where('meta_key', '_sku')
    //                 //         ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
    //                 // })
    //                 ->whereHas('categories', function ($query) use ($searchTerm) {
    //                     $query->where('name', 'LIKE', '%' . $searchTerm . '%');
    //                 });
    //         })
    //         ->orderBy('post_modified', 'desc')
    //         ->paginate($perPage);

    //     $products->getCollection()->transform(function ($product) {
    //         $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
    //         $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

    //         return [
    //             'ID' => $product->ID,
    //             'title' => $product->post_title,
    //             'slug' => $product->post_name,
    //             'thumbnail_url' => $thumbnailUrl,
    //             'categories' => $product->categories->map(function ($category) {
    //                 $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
    //                 return [
    //                     'term_id' => $category->term_id,
    //                     'name' => $category->name,
    //                     'visibility' => $visibility ? $visibility : 'public',
    //                 ];
    //             }),
    //             'meta' => $product->meta->map(function ($meta) {
    //                 return [
    //                     'meta_key' => $meta->meta_key,
    //                     'meta_value' => $meta->meta_value
    //                 ];
    //             }),
    //             'post_modified' => $product->post_modified
    //         ];
    //     });

    //     return response()->json($products);
    // }
}
