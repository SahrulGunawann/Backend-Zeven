<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Transaction;

class AdminController extends Controller
{
    public function users(Request $request)
    {
        $search = $request->query('search');
        $users = User::whereIn('role', ['buyer', 'seller'])
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with('addresses')
            ->latest()
            ->get();
        return response()->json($users);
    }

    public function buyers(Request $request)
    {
        $search = $request->query('search');
        $buyers = User::where('role', 'buyer')
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->get();
        return response()->json($buyers);
    }

    public function showUser($id)
    {
        $user = User::with(['orders.seller', 'sellerOrders.buyer', 'products', 'addresses'])->find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        return response()->json($user);
    }

    public function sellers(Request $request)
    {
        $search = $request->query('search');
        $sellers = User::where('role', 'seller')
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->get();
        return response()->json($sellers);
    }

    public function destroyUser($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Jika dia seller, mungkin mau hapus produk dll, setidaknya cascade di DB
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function products()
    {
        $products = Product::with('seller')->latest()->get();
        return response()->json($products);
    }

    public function destroyProduct($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Produk tidak ditemukan'], 404);
        }

        // Hapus Gambar jika ada
        if ($product->image && \Illuminate\Support\Facades\Storage::disk('public')->exists($product->image)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image);
        }

        $product->delete();
        return response()->json(['message' => 'Produk berhasil dihapus oleh admin']);
    }

    public function orders()
    {
        $orders = Order::with(['buyer', 'seller'])->latest()->get();
        return response()->json($orders);
    }

    public function statistics()
    {
        $totalUsers = User::whereIn('role', ['buyer', 'seller'])->count();
        $totalBuyers = User::where('role', 'buyer')->count();
        $totalProducts = Product::count();
        $totalOrders = Order::count();
        $totalTransactions = Order::count();
        $totalSellers = User::where('role', 'seller')->count();

        $totalRevenue = Order::where('status', 'completed')
            ->sum('final_price');

        // Growth calculation
        $currentMonthCount = User::whereIn('role', ['buyer', 'seller'])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $lastMonthCount = User::whereIn('role', ['buyer', 'seller'])
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        $growth = 0;
        if ($lastMonthCount > 0) {
            $growth = (($currentMonthCount - $lastMonthCount) / $lastMonthCount) * 100;
        } elseif ($currentMonthCount > 0) {
            $growth = 100;
        }

        // Chart Data (Last 6 months)
        $labels = [];
        $usersData = [];
        $sellersData = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $labels[] = $month->format('M');
            $usersData[] = User::where('role', 'buyer')
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->count();
            $sellersData[] = User::where('role', 'seller')
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->count();
        }

        // Top Sellers
        $topSellers = User::where('role', 'seller')
            ->withCount([
                'sellerOrders as completed_orders' => function ($query) {
                    $query->where('status', 'completed');
                }
            ])
            ->withSum([
                'sellerOrders as revenue' => function ($query) {
                    $query->where('status', 'completed');
                }
            ], 'final_price')
            ->orderByDesc('revenue')
            ->take(5)
            ->get()
            ->map(function ($seller) {
                return [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'sales' => $seller->completed_orders,
                    'revenue' => 'Rp ' . number_format($seller->revenue ?? 0, 0, ',', '.'),
                    'avatar' => $seller->profile_image
                ];
            });

        // Recent Transactions
        $recentTransactions = Order::with(['buyer', 'seller'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($order) {
                // Ensure statuses match UI expectations (e.g. completed, pending, cancelled)
                $status = strtolower($order->status);
                if (!in_array($status, ['completed', 'pending', 'cancelled'])) {
                    if ($status === 'success')
                        $status = 'completed';
                    else if ($status === 'failed')
                        $status = 'cancelled';
                    else
                        $status = 'pending';
                }

                return [
                    'id' => '#' . str_pad($order->id, 5, '0', STR_PAD_LEFT),
                    'raw_id' => $order->id,
                    'seller' => $order->seller->name ?? 'Unknown',
                    'buyer' => $order->buyer->name ?? 'Unknown',
                    'amount' => 'Rp ' . number_format($order->final_price, 0, ',', '.'),
                    'status' => $status
                ];
            });

        // Product Specific Stats
        $outOfStock = Product::where('stock', 0)->count();

        return response()->json([
            'total_users' => $totalUsers,
            'total_buyers' => $totalBuyers,
            'total_sellers' => $totalSellers,
            'total_products' => $totalProducts,
            'out_of_stock_products' => $outOfStock,
            'total_orders' => $totalOrders,
            'total_transactions' => $totalTransactions,
            'total_revenue' => $totalRevenue,
            'growth' => round($growth, 1),
            'chart_data' => [
                'labels' => $labels,
                'users' => $usersData,
                'sellers' => $sellersData
            ],
            'top_sellers' => $topSellers,
            'recent_transactions' => $recentTransactions
        ]);
    }

    public function notifications()
    {
        $adminId = auth()->id();
        $notifications = collect();

        // 1. Chat Notifications (Unread Messages)
        $newChats = \Illuminate\Support\Facades\DB::table('messages')
            ->join('users', 'messages.sender_id', '=', 'users.id')
            ->where('messages.receiver_id', $adminId)
            ->where('messages.is_read', 0)
            ->select('messages.*', 'users.name as sender_name', 'users.profile_image', 'users.role')
            ->orderBy('messages.created_at', 'desc')
            ->get()
            ->map(function ($chat) {
                // Check if marked as read in cache
                if (cache()->has("notif_read_chat_{$chat->id}"))
                    return null;

                $msgText = $chat->message;
                try {
                    $msgText = decrypt($msgText);
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    // Fallback to raw message if not encrypted
                }

                return [
                    'id' => 'chat_' . $chat->id,
                    'type' => 'chat',
                    'title' => 'Pesan Baru',
                    'message' => 'Pesan dari ' . $chat->sender_name . ': "' . \Illuminate\Support\Str::limit($msgText, 40) . '"',
                    'time' => \Carbon\Carbon::parse($chat->created_at)->format('Y-m-d H:i:s'),
                    'time_human' => \Carbon\Carbon::parse($chat->created_at)->diffForHumans(),
                    'is_read' => false,
                    'icon' => 'message-square',
                    'bg_color' => 'bg-purple-100',
                    'text_color' => 'text-purple-600'
                ];
            })->filter();
        $notifications = $notifications->concat($newChats);



        // 3. Out of Stock Products (Recently updated to 0)
        $outOfStock = Product::where('stock', 0)
            ->where('updated_at', '>=', now()->subDay())
            ->with('seller')
            ->latest()
            ->get()
            ->map(function ($product) {
                // Check if marked as read in cache
                if (cache()->has("notif_read_stock_{$product->id}"))
                    return null;

                return [
                    'id' => 'stock_' . $product->id,
                    'type' => 'stock',
                    'title' => 'Stok Habis!',
                    'message' => 'Produk "' . $product->name . '" milik ' . ($product->seller->name ?? 'Seller') . ' telah habis.',
                    'time' => $product->updated_at->format('Y-m-d H:i:s'),
                    'time_human' => $product->updated_at->diffForHumans(),
                    'is_read' => false,
                    'icon' => 'package-x',
                    'bg_color' => 'bg-red-100',
                    'text_color' => 'text-red-600'
                ];
            })->filter();
        $notifications = $notifications->concat($outOfStock);

        // 2. Pending Withdrawals Notif
        $pendingWithdrawals = \App\Models\Withdrawal::with('user')
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(function ($w) {
                // Check if marked as read in cache
                if (cache()->has("notif_read_withdrawal_{$w->id}"))
                    return null;

                return [
                    'id' => 'withdrawal_' . $w->id,
                    'type' => 'withdrawal',
                    'title' => 'Tarik Saldo Baru!',
                    'message' => 'Seller "' . ($w->user->name ?? 'Seller') . '" mengajukan penarikan Rp ' . number_format($w->amount, 0, ',', '.') . '.',
                    'time' => $w->created_at->format('Y-m-d H:i:s'),
                    'time_human' => $w->created_at->diffForHumans(),
                    'is_read' => false,
                    'icon' => 'dollar-sign',
                    'bg_color' => 'bg-amber-100',
                    'text_color' => 'text-amber-600'
                ];
            })->filter();
        $notifications = $notifications->concat($pendingWithdrawals);

        // Sort by time
        $notifications = $notifications->sortByDesc('time')->values();

        return response()->json([
            'count' => $notifications->count(),
            'notifications' => $notifications
        ]);
    }

    public function markNotificationAsRead($id)
    {
        $parts = explode('_', $id);
        $type = $parts[0] ?? null;
        $realId = $parts[1] ?? null;

        if ($type && $realId) {
            // Persist read state for 24h
            cache()->put("notif_read_{$type}_{$realId}", true, now()->addHours(24));
        }

        return response()->json(['success' => true]);
    }

    public function withdrawals()
    {
        $withdrawals = \App\Models\Withdrawal::with('user')
            ->latest()
            ->get()
            ->map(function ($w) {
                return [
                    'id' => $w->id,
                    'seller_name' => $w->user->name ?? 'Seller',
                    'amount' => 'Rp ' . number_format($w->amount, 0, ',', '.'),
                    'amount_raw' => $w->amount,
                    'bank_name' => $w->bank_name,
                    'account_number' => $w->account_number,
                    'account_name' => $w->account_name,
                    'status' => $w->status,
                    'rejected_reason' => $w->rejected_reason,
                    'proof_image' => $w->proof_image ? url('storage/' . $w->proof_image) : null,
                    'admin_note' => $w->admin_note,
                    'date' => $w->created_at->format('d M Y, H:i')
                ];
            });

        return response()->json($withdrawals);
    }

    public function approveWithdrawal(Request $request, $id)
    {
        $request->validate([
            'proof_image' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'admin_note' => 'nullable|string'
        ]);

        $withdrawal = \App\Models\Withdrawal::findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json(['message' => 'Status penarikan sudah tidak pending'], 400);
        }

        if ($request->hasFile('proof_image')) {
            $path = $request->file('proof_image')->store('proofs', 'public');
            $withdrawal->proof_image = $path;
        }

        $withdrawal->admin_note = $request->admin_note;
        $withdrawal->status = 'completed';
        $withdrawal->save();

        return response()->json([
            'message' => 'Penarikan saldo berhasil disetujui',
            'withdrawal' => $withdrawal
        ]);
    }

    public function rejectWithdrawal(Request $request, $id)
    {
        $request->validate([
            'rejected_reason' => 'required|string',
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'admin_note' => 'nullable|string'
        ]);

        $withdrawal = \App\Models\Withdrawal::findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json(['message' => 'Status penarikan sudah tidak pending'], 400);
        }

        if ($request->hasFile('proof_image')) {
            $path = $request->file('proof_image')->store('proofs', 'public');
            $withdrawal->proof_image = $path;
        }

        $withdrawal->status = 'rejected';
        $withdrawal->rejected_reason = $request->rejected_reason;
        $withdrawal->admin_note = $request->admin_note;
        $withdrawal->save();

        return response()->json([
            'message' => 'Penarikan saldo ditolak',
            'withdrawal' => $withdrawal
        ]);
    }
}
