<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Wishlist;
use App\Models\Cart;
use App\Models\Address;
use App\Models\Transaction;
use App\Models\Message;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function registerBuyer(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'buyer'
        ]);

        return response()->json([
            'message' => 'Register buyer berhasil',
            'user' => $user
        ]);
    }

    public function registerSeller(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'seller'
        ]);

        return response()->json([
            'message' => 'Register seller berhasil',
            'user' => $user
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Email atau password salah'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => $user
        ]);
    }

    public function loginGoogle(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required',
            'google_id' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'google_id' => $request->google_id,
                'role' => 'seller', // Default as seller for social login (Testing)
                'profile_image' => $request->avatar
            ]);
        } else {
            // Update google_id if not exists
            if (!$user->google_id) {
                $user->google_id = $request->google_id;
            }
            if (!$user->profile_image && $request->avatar) {
                $user->profile_image = $request->avatar;
            }
            $user->save();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login Google berhasil',
            'token' => $token,
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil'
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $request->user()->id,
            'current_password' => 'required_with:password',
            'password' => 'nullable|min:6|confirmed',
            'phone' => 'nullable|string|max:20',
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan.',
            'current_password.required_with' => 'Kata sandi lama wajib diisi jika ingin mengubah kata sandi.',
            'password.min' => 'Kata sandi baru minimal harus 6 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.'
        ]);

        $user = $request->user();

        if ($request->filled('password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'message' => 'Kata sandi lama tidak sesuai'
                ], 422);
            }
            $user->password = bcrypt($request->password);
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->address = $request->address;

        $user->save();

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'user' => $user
        ]);
    }

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->profile_image = $path;
        $user->save();

        return response()->json([
            'message' => 'Avatar berhasil diperbarui',
            'profile_image' => asset('storage/' . $path),
            'user' => $user
        ]);
    }

    public function deleteAvatar()
    {
        $user = auth()->user();
        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
            $user->profile_image = null;
            $user->save();
        }
        return response()->json(['message' => 'Avatar deleted', 'user' => $user]);
    }

    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string'
        ]);

        $user = auth()->user();
        $user->fcm_token = $request->fcm_token;
        $user->save();

    }
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // 1. Cek apakah ada pesanan yang MASIH BERJALAN (Pending/Processed/Shipped)
        // Jika masih ada transaksi aktif, tetap tidak boleh dihapus demi keamanan transaksi
        $activeOrders = Order::where(function($query) use ($user) {
            $query->where('user_id', $user->id)
                  ->orWhere('seller_id', $user->id);
        })->whereIn('status', ['pending', 'processed', 'shipped'])->count();

        if ($activeOrders > 0) {
            return response()->json([
                'message' => 'Tidak dapat menghapus akun karena masih ada pesanan yang sedang berjalan (aktif). Selesaikan transaksi Anda terlebih dahulu.'
            ], 422);
        }

        try {
            DB::beginTransaction();
            
            // MATIKAN Pengecekan Foreign Key agar tidak ada tabel yang menghalangi
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // 1. Hapus data Pesanan & Item Pesanan
            $orderIds = Order::where('user_id', $user->id)
                             ->orWhere('seller_id', $user->id)
                             ->pluck('id');

            if ($orderIds->count() > 0) {
                OrderItem::whereIn('order_id', $orderIds)->delete();
                Order::whereIn('id', $orderIds)->delete();
            }

            // 2. Hapus data Chat/Pesan
            Message::where('sender_id', $user->id)
                   ->orWhere('receiver_id', $user->id)
                   ->delete();

            // 3. Hapus Produk & Review jika Seller
            if ($user->role === 'seller') {
                $productIds = Product::where('seller_id', $user->id)->pluck('id');
                Review::whereIn('product_id', $productIds)->delete();
                Product::where('seller_id', $user->id)->delete();
            }

            // 4. Hapus Review, Transaction, Wishlist, Cart, Address
            Review::where('user_id', $user->id)->delete();
            Transaction::where('user_id', $user->id)->delete();
            Wishlist::where('user_id', $user->id)->delete();
            Cart::where('user_id', $user->id)->delete();
            Address::where('user_id', $user->id)->delete();

            // 5. Hapus Avatar
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }

            // 6. Hapus Token & Akun User
            $user->tokens()->delete();
            $user->delete();

            // HIDUPKAN KEMBALI Pengecekan Foreign Key
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Akun Anda berhasil dihapus secara permanen.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            // Pastikan foreign key hidup kembali jika gagal
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus akun: ' . $e->getMessage() . ' (File: ' . basename($e->getFile()) . ' Line: ' . $e->getLine() . ')'
            ], 500);
        }
    }
}
