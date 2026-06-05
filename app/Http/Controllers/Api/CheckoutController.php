<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Models\Voucher;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function checkout(Request $request)
    {
        $request->validate([
            'shipping_address' => 'required',
            'voucher_code' => 'nullable',
            'cart_item_ids' => 'nullable|array',
            'cart_item_ids.*' => 'integer',
            'payment_method' => 'nullable|in:COD,DompetX'
        ]);

        // ambil cart user (buat jika belum ada)
        $cart = Cart::with('items.product')
            ->firstOrCreate(['user_id' => auth()->id()]);

        // Filter items based on cart_item_ids if provided
        $itemsToProcess = $cart->items;
        if ($request->has('cart_item_ids') && !empty($request->cart_item_ids)) {
            $itemsToProcess = $cart->items->filter(function ($item) use ($request) {
                return in_array($item->id, $request->cart_item_ids);
            });
        }

        if ($itemsToProcess->isEmpty()) {
            return response()->json([
                'message' => 'Keranjang belanja kosong atau item tidak ditemukan'
            ], 400);
        }

        // hitung total
        $total = 0;

        foreach ($itemsToProcess as $item) {

            $product = $item->product;

            // cek stock
            if ($item->quantity > $product->stock) {

                return response()->json([
                    'message' => 'Stok produk tidak mencukupi',
                    'product' => $product->name
                ], 400);
            }

            $total += $product->price * $item->quantity;
        }

        $discount = 0;
        $voucherId = null;

        // cek voucher
        if ($request->voucher_code) {

            $voucher = Voucher::where('code', $request->voucher_code)
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->first();

            if (!$voucher) {

                return response()->json([
                    'message' => 'Voucher tidak valid'
                ], 400);
            }

            // cek quota
            if ($voucher->used >= $voucher->quota) {

                return response()->json([
                    'message' => 'Voucher habis'
                ], 400);
            }

            // hitung diskon
            $discount = ($voucher->discount_percent / 100) * $total;

            // max discount
            if ($discount > $voucher->max_discount) {

                $discount = $voucher->max_discount;
            }

            // increment used
            $voucher->increment('used');

            $voucherId = $voucher->id;
        }

        // ambil seller pertama (asumsi satu checkout satu seller untuk simplisitas)
        $sellerId = $itemsToProcess->first()->product->seller_id;

        // buat order
        $order = Order::create([
            'buyer_id' => auth()->id(),
            'seller_id' => $sellerId,
            'total_price' => $total,
            'voucher_id' => $voucherId,
            'discount_amount' => $discount,
            'final_price' => $total - $discount,
            'status' => ($request->payment_method === 'COD') ? 'processed' : 'pending',
            'shipping_address' => $request->shipping_address
        ]);

        // simpan order items
        foreach ($itemsToProcess as $item) {

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $item->product->price,
                'subtotal' => $item->product->price * $item->quantity
            ]);

            // kurangi stock
            $product = $item->product;

            $product->stock -= $item->quantity;

            $product->save();
        }

        // buat transaksi
        Transaction::create([
            'order_id' => $order->id,
            'payment_method' => $request->input('payment_method', 'COD'),
            'payment_status' => 'pending'
        ]);

        // hapus hanya item yang diproses dari cart
        foreach ($itemsToProcess as $item) {
            $item->delete();
        }

        return response()->json([
            'message' => 'Checkout berhasil',
            'order' => $order
        ]);
    }
}
