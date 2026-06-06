<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PaymentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// PUBLIC ROUTES
Route::post('/register-buyer', [AuthController::class, 'registerBuyer']);
Route::post('/register-seller', [AuthController::class, 'registerSeller']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login/google', [AuthController::class, 'loginGoogle']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products/{productId}/reviews', [ReviewController::class, 'productReviews']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::post('/payment/notification', [PaymentController::class, 'notification'])->name('payment.notification');

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/orders/{id}', [OrderController::class, 'show']);

    // PAYMENT ROUTES
    Route::post('/payment/get-token', [PaymentController::class, 'getToken']);
    Route::get('/payment/check-status/{midtransOrderId}', [PaymentController::class, 'checkStatus']);

    Route::post('/logout', [AuthController::class, 'logout']);

    // PROFILE ROUTES (semua role bisa akses)
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile/avatar', [AuthController::class, 'updateAvatar']);
    Route::delete('/profile/avatar', [AuthController::class, 'deleteAvatar']);
    Route::delete('/profile', [AuthController::class, 'deleteAccount']);
    Route::post('/vouchers/check', [VoucherController::class, 'check']);
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::post('/update-fcm-token', [AuthController::class, 'updateFcmToken']);

    // SELLER ROUTES
    Route::middleware('role:seller')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);
        Route::get('/seller/products', [ProductController::class, 'sellerProducts']);
        Route::get('/seller/statistics', [SellerController::class, 'statistics']);
        Route::get('/seller/transactions', [SellerController::class, 'transactions']);
        Route::get('/seller/notifications', [SellerController::class, 'notifications']);
        Route::post('/seller/notifications/{id}/read', [SellerController::class, 'markNotificationAsRead']);
        Route::get('/seller/reviews', [ReviewController::class, 'sellerReviews']);
        Route::post('/seller/reviews/{id}/reply', [ReviewController::class, 'reply']);
        Route::get('/seller-orders', [OrderController::class, 'sellerOrders']);
        Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
        Route::post('/seller/withdraw', [SellerController::class, 'withdraw']);
    });

    // BUYER ROUTES
    Route::middleware('role:buyer')->group(function () {
        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart/add', [CartController::class, 'addToCart']);
        Route::delete('/cart/item/{id}', [CartController::class, 'removeItem']);
        Route::put('/cart/item/{id}', [CartController::class, 'updateQuantity']);
        Route::post('/checkout', [CheckoutController::class, 'checkout']);
        Route::get('/my-orders', [OrderController::class, 'myOrders']);
        Route::post('/orders/{id}/complete', [OrderController::class, 'completeOrder']);
        Route::post('/orders/{id}/cancel', [OrderController::class, 'cancelOrder']);
        Route::post('/reviews', [ReviewController::class, 'store']);
        Route::get('/buyer/replied-reviews', [ReviewController::class, 'buyerRepliedReviews']);
    });

    // ADMIN ROUTES
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/statistics', [AdminController::class, 'statistics']);
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::get('/admin/buyers', [AdminController::class, 'buyers']);
        Route::get('/admin/users/{id}', [AdminController::class, 'showUser']);
        Route::get('/admin/sellers', [AdminController::class, 'sellers']);
        Route::delete('/admin/users/{id}', [AdminController::class, 'destroyUser']);
        Route::get('/admin/products', [AdminController::class, 'products']);
        Route::delete('/admin/products/{id}', [AdminController::class, 'destroyProduct']);
        Route::get('/admin/orders', [AdminController::class, 'orders']);
        Route::get('/admin/notifications', [AdminController::class, 'notifications']);
        Route::post('/admin/notifications/{id}/read', [AdminController::class, 'markNotificationAsRead']);

        Route::post('/vouchers', [VoucherController::class, 'store']);
        Route::delete('/vouchers/{id}', [VoucherController::class, 'destroy']);
        
        Route::get('/admin/withdrawals', [AdminController::class, 'withdrawals']);
        Route::post('/admin/withdrawals/{id}/approve', [AdminController::class, 'approveWithdrawal']);
        Route::post('/admin/withdrawals/{id}/reject', [AdminController::class, 'rejectWithdrawal']);
    });

    // WISHLIST
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle']);
    Route::get('/wishlist/check/{productId}', [WishlistController::class, 'check']);

    // ADDRESSES
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);
    Route::post('/addresses/{id}/set-main', [AddressController::class, 'setMain']);

    // CHAT (Available for all roles)
    Route::post('/chat/send', [ChatController::class, 'sendMessage']);
    Route::get('/chat/list', [ChatController::class, 'getChatList']);
    Route::get('/chat/admin-id', [ChatController::class, 'getAdminId']);
    Route::get('/chat/user/{id}', [ChatController::class, 'getUserInfo']);
    Route::post('/chat/read/{userId}', [ChatController::class, 'markAsRead']);
    Route::delete('/chat/clear/{userId}', [ChatController::class, 'clearChat']);
    Route::get('/chat/{userId}', [ChatController::class, 'getMessages']);
    Route::put('/chat/message/{id}', [ChatController::class, 'updateMessage']);
    Route::delete('/chat/message/{id}', [ChatController::class, 'deleteMessage']);
});
