<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LayoutController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PayPalController;
use App\Http\Controllers\Woo\CartController;
use App\Http\Controllers\Woo\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WooCommerceController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', [LoginController::class, 'login']);

Route::group(['middleware' => ['jwt.auth']], function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/profile', [LoginController::class, 'me']);

    Route::post('/layout', [LayoutController::class, 'store']);
    Route::put('/layout/{id}', [LayoutController::class, 'update']);
    Route::delete('/layout/{id}', [LayoutController::class, 'destroy']);
    Route::post('/mediafile', [LayoutController::class, 'uploadFile']);



    Route::post('/media/upload', [MediaController::class, 'uploadFile']);
    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/{id}', [MediaController::class, 'show']);
    Route::put('/media/{id}', [MediaController::class, 'update']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy']);


    Route::get('/get-u-addresses',[WooCommerceController::class,'getUAddresses']);
});

Route::get('/layout', [LayoutController::class, 'layouts']);
Route::get('/position/{layout}', [LayoutController::class, 'position']);
Route::get('/positionLayout/{layout}/{position}', [LayoutController::class, 'positionLayout']);
Route::get('/positionLayout/{page}', [LayoutController::class, 'pageLayout']);

Route::get('/categoryProduct/{slug}', [ProductController::class, 'categoryProduct']);
Route::get('/brandProduct/{slug}', [ProductController::class, 'brandProducts']);
Route::get('/searchProducts', [ProductController::class, 'searchProducts']); //search in product title 
Route::get('/searchProductsBySKU', [ProductController::class, 'searchProductsBySKU']); //in product sku
Route::get('/searchProductsByCAT', [ProductController::class, 'searchProductsByCAT']); //in cat 
Route::get('/searchProductsALL', [ProductController::class, 'searchProductsAll']); //in pro sku cat


Route::get('/sidebar', [ProductController::class, 'sidebar']);

Route::get('/cart', [CartController::class, 'index']);
Route::get('/cart-products', [CartController::class, 'show']);
// Route::post('/cart/add', [CartController::class, 'addToCart']);

Route::get('/log', function () {
    return response()->json(['status' => 'error', 'redirect_url' => '/login']);
})->name('login');





Route::post('/add-to-cart', [WooCommerceController::class, 'addToCart']);

Route::get('/cart/get', [WooCommerceController::class, 'getCart']);
Route::get('/product/{slug}', [WooCommerceController::class, 'show']);
Route::post('cart/add', [CartController::class, 'addToCart']);



Route::get('/get-all-orders',[WooCommerceController::class,'getAllOrders']);



Route::post('/create-new-order',[WooCommerceController::class,'createNewOrder']);
Route::get('/get-all-payment-option',[WooCommerceController::class,'allPaymentGate']);
Route::get('/get-all-payment-option/{method}',[WooCommerceController::class,'getPaymentMethod']);
Route::get('/get-shipping-zone',[WooCommerceController::class,'getShippingZone']);


Route::post('/create-payment', [PayPalController::class, 'createPayment']);
Route::post('/execute-payment', [PayPalController::class, 'executePayment']);


Route::get('/test',[LoginController::class,'fetchApiData']);