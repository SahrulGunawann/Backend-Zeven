<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. USERS
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 100)->unique();
            $table->string('google_id', 255)->nullable();
            $table->string('password', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->enum('role', ['buyer', 'seller', 'admin'])->default('buyer');
            $table->string('profile_image', 255)->nullable();
            $table->text('fcm_token')->nullable();
            $table->timestamps();
        });

        // 3. CATEGORIES
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('icon', 255)->nullable();
            $table->timestamps();
        });

        // 4. PRODUCTS
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            $table->string('name', 255);
            $table->string('category', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->integer('stock')->default(0);
            $table->string('image', 255)->nullable();
            $table->timestamps();
        });

        // 5. PRODUCT IMAGES
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('product_id')->unsigned()->index();
            $table->string('image_path', 255);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // 6. ADDRESSES
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('label', 255)->nullable();
            $table->string('receiver_name', 255)->nullable();
            $table->string('phone_number', 255)->nullable();
            $table->text('full_address')->nullable();
            $table->boolean('is_main')->default(false);
            $table->timestamps();
        });

        // 7. CARTS
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        // 8. CART ITEMS
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        // 9. VOUCHERS
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->integer('discount_percent');
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('quota')->default(0);
            $table->integer('used')->default(0);
            $table->timestamps();
        });

        // 10. ORDERS
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers');
            $table->decimal('total_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->decimal('final_price', 12, 2);
            $table->enum('status', ['pending', 'processed', 'shipped', 'completed', 'canceled'])->default('pending');
            $table->text('shipping_address')->nullable();
            $table->timestamps();
        });

        // 11. ORDER ITEMS
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products');
            $table->integer('quantity');
            $table->decimal('price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        // 12. TRANSACTIONS
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->onDelete('cascade');
            $table->string('payment_method', 255)->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'failed'])->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });

        // 13. MESSAGES
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('receiver_id')->constrained('users')->onDelete('cascade');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        // 14. REVIEWS
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('order_id')->constrained('orders');
            $table->integer('rating');
            $table->text('review')->nullable();
            $table->text('reply')->nullable();
            $table->timestamps();
        });

        // 15. WISHLISTS
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->bigInteger('product_id')->unsigned();
            $table->timestamps();
        });

        // 16. WITHDRAWALS
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('bank_name', 255);
            $table->string('account_number', 255);
            $table->string('account_name', 255);
            $table->enum('status', ['pending', 'completed', 'rejected'])->default('pending');
            $table->string('rejected_reason', 255)->nullable();
            $table->string('proof_image', 255)->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('users');

        Schema::enableForeignKeyConstraints();
    }
};
