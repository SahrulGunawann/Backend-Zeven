<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    // LIHAT CART
    public function index()
    {
        $cart = Cart::with('items.product')
            ->where('user_id', auth()->id())
            ->first();

        return response()->json($cart);
    }

    // ADD TO CART
    public function addToCart(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'quantity' => 'required|numeric|min:1'
        ]);

        $product = Product::findOrFail($request->product_id);

        // cek cart user
        $cart = Cart::firstOrCreate([
            'user_id' => auth()->id()
        ]);

        // cek item sudah ada?
        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        if ($cartItem) {
            if ($request->replace_quantity) {
                $cartItem->quantity = $request->quantity;
            } else {
                $cartItem->quantity += $request->quantity;
            }
            $cartItem->save();
        } else {
            $cartItem = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $request->quantity
            ]);
        }

        return response()->json([
            'message' => 'Produk berhasil ditambahkan ke keranjang',
            'data' => $cartItem
        ]);
    }

    // UPDATE QUANTITY
    // HAPUS ITEM CART
    public function removeItem($id)
    {
        $item = CartItem::findOrFail($id);
        $item->delete();

        return response()->json([
            'message' => 'Item berhasil dihapus dari keranjang'
        ]);
    }

    // UPDATE QUANTITY
    public function updateQuantity(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|numeric|min:1'
        ]);

        $item = CartItem::findOrFail($id);
        $item->quantity = $request->quantity;
        $item->save();

        return response()->json([
            'message' => 'Jumlah pesanan berhasil diperbarui'
        ]);
    }
}
