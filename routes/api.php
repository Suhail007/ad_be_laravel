<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Woo\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login',[LoginController::class,'login']);
Route::post('/logout',[LoginController::class,'logout']);


Route::get('/categoryProduct/{slug}',[ProductController::class,'categoryProduct']); 

Route::get('/searchProducts',[ProductController::class,'searchProducts']); //search in product title 
Route::get('/searchProductsBySKU',[ProductController::class,'searchProductsBySKU']); //in product sku
Route::get('/searchProductsByCAT',[ProductController::class,'searchProductsByCAT']); //in cat 
Route::get('/searchProductsALL',[ProductController::class,'searchProductsAll']); //in pro sku cat


Route::get('/sidebar',[ProductController::class,'sidebar']);

Route::get('/log', function(){return response()->json(['status'=>'error','redirect_url'=>'/login']);})->name('login');


Route::group(['middleware' => ['auth:api']], function () {
});
