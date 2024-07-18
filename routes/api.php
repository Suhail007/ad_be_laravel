<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LayoutController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PayPalController;
use App\Http\Controllers\Woo\WooCartController;
use App\Http\Controllers\Woo\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WooCommerceController;
// routes/web.php or routes/api.php
use App\Http\Controllers\CartController;
use App\Http\Controllers\DiscountRuleController;
use App\Http\Controllers\MyAcccountController;
use App\Http\Controllers\OrderController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', [LoginController::class, 'login']);

Route::group(['middleware' => ['jwt.auth']], function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/profile', [LoginController::class, 'me']);

    //admin layout
    Route::post('/layout', [LayoutController::class, 'store']);
    Route::put('/layout/{id}', [LayoutController::class, 'update']);
    Route::delete('/layout/{id}', [LayoutController::class, 'destroy']);
    Route::post('/mediafile', [LayoutController::class, 'uploadFile']);
    //media 
    Route::post('/media/upload', [MediaController::class, 'uploadFile']);
    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/{id}', [MediaController::class, 'show']);
    Route::put('/media/{id}', [MediaController::class, 'update']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy']);
    Route::get('/get-u-addresses',[WooCommerceController::class,'getUAddresses']);

    //myaccount
    Route::get('/my-account/addresses',[MyAcccountController::class,'getUserAddresses']);

    //user Cart
    Route::get('/cart/{userId}', [CartController::class, 'getCart']);
    Route::delete('/cart/{id}', [CartController::class, 'deleteFromCart']);
    Route::post('/cart/bulk-add', [CartController::class, 'bulkAddToCart']);
    Route::post('/cart/update', [CartController::class, 'updateCartQuantity']);
    Route::post('/cart/empty',[CartController::class,'empty']);

    //checkout Cart with category Id

    //payment 
    Route::get('/payment-price',[PayPalController::class, 'me']);
    Route::post('/process-payment', [PayPalController::class, 'processPayment']);


    //user get Order
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{id}', [OrderController::class, 'show']);

    //discount api
    Route::get('/cart-discount',[DiscountRuleController::class,'index']);
});
//Layouts Public
Route::get('/layout', [LayoutController::class, 'layouts']);
Route::get('/position/{layout}', [LayoutController::class, 'position']);
Route::get('/positionLayout/{layout}/{position}', [LayoutController::class, 'positionLayout']);
Route::get('/positionLayout/{page}', [LayoutController::class, 'pageLayout']);

Route::get('/categoryProduct/{slug}', [ProductController::class, 'categoryProduct']);
Route::get('/brandProduct/{slug}', [ProductController::class, 'brandProducts']);
Route::get('/searchProducts', [ProductController::class, 'searchProducts']); 
Route::get('/searchProductsBySKU', [ProductController::class, 'searchProductsBySKU']); //in product sku
Route::get('/searchProductsByCAT', [ProductController::class, 'searchProductsByCAT']); //in cat 
Route::get('/searchProductsALL', [ProductController::class, 'searchProductsAll']); //in pro sku cat

Route::get('/sidebar', [ProductController::class, 'sidebar']);



Route::get('/cart', [WooCartController::class, 'index']);
Route::get('/cart-products', [WooCartController::class, 'show']);
// Route::post('/cart/add', [CartController::class, 'addToCart']);

//product page
Route::get('/product/{slug}', [WooCommerceController::class, 'show']);
Route::get('products/{id}/related', [ProductController::class, 'getRelatedProducts']);

Route::post('/create-new-order',[OrderController::class,'createNewOrder']);

Route::get('/get-all-payment-option',[WooCommerceController::class,'allPaymentGate']);
Route::get('/get-all-payment-option/{method}',[WooCommerceController::class,'getPaymentMethod']);
Route::get('/get-shipping-zone',[WooCommerceController::class,'getShippingZone']);


// Route::post('/create-payment', [PayPalController::class, 'createPayment']);
// Route::post('/execute-payment', [PayPalController::class, 'executePayment']);


Route::get('/get-all-orders',[WooCommerceController::class,'getAllOrders']);

Route::get('/log', function () {
    return response()->json(['status' => 'error', 'redirect_url' => '/login']);
})->name('login');