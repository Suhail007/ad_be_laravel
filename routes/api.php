<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Woo\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// Route::post('/register',[LoginController::class,'register']);
Route::post('/login',[LoginController::class,'login']);
Route::post('/logout',[LoginController::class,'logout']);

// Route::post('/refreshToken',[LoginController::class,'refreshToken']);
// Route::get('/search',[ProductController::class,'search']);

Route::get('/search',[ProductController::class,'searchProducts']); 

Route::get('/categoryProducts',[ProductController::class,'categoryProduct']); //in product title 
Route::get('/categoryProductsBySKU',[ProductController::class,'searchProductsBySKU']); //in product sku
Route::get('/categoryProductsByCAT',[ProductController::class,'searchProductsByCAT']); //in cat 
Route::get('/categoryProductsALL',[ProductController::class,'searchProductsAll']); //in pro sku cat

Route::get('/sidebar',[ProductController::class,'sidebar']);


Route::group(['middleware' => ['auth:api']], function () {
    
});
