<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class SellerController extends Controller
{
    public function statistics()
    {
        $sellerId = auth()->id();

        // 1. Basic Stats
        $totalProducts = Product::where('seller_id', $sellerId)->count();
        $pendingOrders = Order::where('seller_id', $sellerId)->where('status', 'pending')->count();
        $completedOrders = Order::where('seller_id', $sellerId)->where('status', 'completed')->count();

        $revenue = Order::where('seller_id', $sellerId)
            ->where('status', 'completed')
            ->sum('final_price');

        // 2. Growth (based on month-over-month revenue)
        $currentMonthRevenue = Order::where('seller_id', $sellerId)
            ->where('status', 'completed')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('final_price');

        $lastMonthRevenue = Order::where('seller_id', $sellerId)
            ->where('status', 'completed')
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('final_price');

        $growth = 0;
        if ($lastMonthRevenue > 0) {
            $growth = (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100;
        } elseif ($currentMonthRevenue > 0) {
            $growth = 100;
        }

        // 3. Recent Orders
        $recentOrders = Order::with(['buyer', 'items.product'])
            ->where('seller_id', $sellerId)
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($order) {
                // Get one product name from items for display
                $item = $order->items->first();
                $productName = $item && $item->product ? $item->product->name : 'No items';

                return [
                    'id' => '#ORD-' . str_pad($order->id, 5, '0', STR_PAD_LEFT),
                    'customer' => $order->buyer->name ?? 'Unknown',
                    'product' => $productName,
                    'amount' => 'Rp ' . number_format($order->final_price, 0, ',', '.'),
                    'status' => $order->status
                ];
            });

        // 4. Top Products
        $topProducts = Product::where('seller_id', $sellerId)
            ->withCount([
                'orderItems as total_sales' => function ($query) {
                    $query->whereHas('order', function ($q) {
                        $q->where('status', 'completed');
                    });
                }
            ])
            ->orderByDesc('total_sales')
            ->take(5)
            ->get()
            ->map(function ($product) {
                return [
                    'name' => $product->name,
                    'sales' => $product->total_sales ?? 0,
                    'price' => 'Rp ' . number_format($product->price, 0, ',', '.'),
                    'image' => $product->image_url
                ];
            });

        // 5. Monthly Revenue for Chart
        $chartLabels = [];
        $chartData = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $chartLabels[] = $month->format('M');
            $chartData[] = (int) Order::where('seller_id', $sellerId)
                ->where('status', 'completed')
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->sum('final_price');
        }

        // 6. Recent Chats
        $recentChats = DB::table('messages')
            ->join('users', 'messages.sender_id', '=', 'users.id')
            ->where('messages.receiver_id', $sellerId)
            ->select('users.id', 'users.name', 'users.profile_image', 'messages.message', 'messages.created_at', 'messages.is_read')
            ->orderBy('messages.created_at', 'desc')
            ->get()
            ->unique('id')
            ->take(5)
            ->map(function ($chat) {
                // Manually handle profile image URL if it's not a full URL
                $avatar = $chat->profile_image;
                if ($avatar && !filter_var($avatar, FILTER_VALIDATE_URL)) {
                    $avatar = asset('storage/' . $avatar);
                }

                $msgText = $chat->message;
                try {
                    $msgText = decrypt($msgText);
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    // Fallback to raw message if not encrypted
                }

                return [
                    'user_id' => $chat->id,
                    'name' => $chat->name,
                    'avatar' => $avatar,
                    'last_message' => \Illuminate\Support\Str::limit($msgText, 30),
                    'time' => \Carbon\Carbon::parse($chat->created_at)->diffForHumans(),
                    'is_read' => $chat->is_read
                ];
            })->values();

        return response()->json([
            'total_products' => $totalProducts,
            'pending_orders' => $pendingOrders,
            'completed_orders' => $completedOrders,
            'revenue' => 'Rp ' . number_format($revenue, 0, ',', '.'),
            'growth' => round($growth, 1),
            'recent_orders' => $recentOrders,
            'top_products' => $topProducts,
            'recent_chats' => $recentChats,
            'chart' => [
                'labels' => $chartLabels,
                'data' => $chartData
            ]
        ]);
    }
    public function transactions()
    {
        $sellerId = auth()->id();

        $totalRevenue = Order::where('seller_id', $sellerId)
            ->where('status', 'completed')
            ->sum('final_price');

        $pendingRevenue = Order::where('seller_id', $sellerId)
            ->whereIn('status', ['pending', 'processed', 'shipped'])
            ->sum('final_price');

        $platformFeeRate = 0.02; // 2% fee example for demo
        $totalFees = $totalRevenue * $platformFeeRate;

        // Calculate actual net completed revenue
        $totalNetCompletedRevenue = Order::where('seller_id', $sellerId)
            ->where('status', 'completed')
            ->get()
            ->sum(function ($order) use ($platformFeeRate) {
                return $order->final_price * (1 - $platformFeeRate);
            });

        $totalWithdrawn = \App\Models\Withdrawal::where('user_id', $sellerId)
            ->whereIn('status', ['pending', 'completed'])
            ->sum('amount');

        $withdrawableBalance = max(0, $totalNetCompletedRevenue - $totalWithdrawn);

        $transactionsPaginated = Order::with(['buyer'])
            ->where('seller_id', $sellerId)
            ->latest()
            ->paginate(10);

        $transactionsMapped = collect($transactionsPaginated->items())->map(function ($order) use ($platformFeeRate) {
            $fee = $order->final_price * $platformFeeRate;
            return [
                'transaction_id' => 'TRX-' . str_pad($order->id, 7, '0', STR_PAD_LEFT),
                'order_number' => '#ORD-' . str_pad($order->id, 5, '0', STR_PAD_LEFT),
                'customer' => $order->buyer->name ?? 'Pembeli',
                'amount' => 'Rp ' . number_format($order->final_price, 0, ',', '.'),
                'fee' => 'Rp ' . number_format($fee, 0, ',', '.'),
                'net' => 'Rp ' . number_format($order->final_price - $fee, 0, ',', '.'),
                'status' => $order->status,
                'date' => $order->created_at->format('d M Y, H:i')
            ];
        })->toArray();

        $transactions = [
            'data' => $transactionsMapped,
            'total' => $transactionsPaginated->total(),
            'per_page' => $transactionsPaginated->perPage(),
            'current_page' => $transactionsPaginated->currentPage(),
            'last_page' => $transactionsPaginated->lastPage(),
        ];

        $withdrawals = \App\Models\Withdrawal::where('user_id', $sellerId)
            ->latest()
            ->get()
            ->map(function ($w) {
                return [
                    'id' => $w->id,
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
            })->toArray();

        return response()->json([
            'stats' => [
                'total_revenue' => 'Rp ' . number_format($totalRevenue, 0, ',', '.'),
                'pending_revenue' => 'Rp ' . number_format($pendingRevenue, 0, ',', '.'),
                'platform_fees' => 'Rp ' . number_format($totalFees, 0, ',', '.'),
                'withdrawable_balance' => 'Rp ' . number_format($withdrawableBalance, 0, ',', '.'),
                'withdrawable_balance_raw' => $withdrawableBalance,
                'total_withdrawn' => 'Rp ' . number_format($totalWithdrawn, 0, ',', '.')
            ],
            'transactions' => $transactions,
            'withdrawals' => $withdrawals
        ]);
    }

    public function withdraw(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10000',
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string'
        ]);

        $sellerId = auth()->id();

        $platformFeeRate = 0.02;
        $totalNetCompletedRevenue = Order::where('seller_id', $sellerId)
            ->where('status', 'completed')
            ->get()
            ->sum(function ($order) use ($platformFeeRate) {
                return $order->final_price * (1 - $platformFeeRate);
            });

        $totalWithdrawn = \App\Models\Withdrawal::where('user_id', $sellerId)
            ->whereIn('status', ['pending', 'completed'])
            ->sum('amount');

        $withdrawableBalance = max(0, $totalNetCompletedRevenue - $totalWithdrawn);

        if ($request->amount > $withdrawableBalance) {
            return response()->json([
                'message' => 'Saldo tidak mencukupi untuk melakukan penarikan'
            ], 400);
        }

        $withdrawal = \App\Models\Withdrawal::create([
            'user_id' => $sellerId,
            'amount' => $request->amount,
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_name' => $request->account_name,
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Permintaan penarikan saldo berhasil diajukan',
            'withdrawal' => $withdrawal
        ]);
    }

    public function notifications()
    {
        $sellerId = auth()->id();
        $data = $this->getNotificationsData($sellerId);

        return response()->json([
            'notifications' => $data['notifications'],
            'unread_count' => $data['count']
        ]);
    }

    public function markAsRead(Request $request)
    {
        $sellerId = auth()->id();
        $data = $this->getNotificationsData($sellerId);

        return response()->json([
            'count' => $data['count'],
            'notifications' => $data['notifications']
        ]);
    }

    private function getNotificationsData($sellerId)
    {
        // 1. Order Notifications
        $orders = Order::with('buyer')
            ->where('seller_id', $sellerId)
            ->whereIn('status', ['processed', 'completed', 'canceled'])
            ->latest()
            ->take(10)
            ->get();

        $orderNotifs = $orders->map(function ($order) {
            $status = $order->status;
            $cacheKey = "notif_read_{$status}_{$order->id}";
            if (cache()->has($cacheKey)) return null;

            $config = [
                'processed' => ['title' => 'Pesanan Baru!', 'msg' => 'perlu diproses', 'icon' => 'shopping-bag', 'bg' => 'bg-blue-100', 'txt' => 'text-blue-600'],
                'completed' => ['title' => 'Saldo Bertambah!', 'msg' => 'berhasil diselesaikan', 'icon' => 'check-circle', 'bg' => 'bg-green-100', 'txt' => 'text-green-600'],
                'canceled'  => ['title' => 'Pesanan Batal', 'msg' => 'telah dibatalkan', 'icon' => 'x-circle', 'bg' => 'bg-red-100', 'txt' => 'text-red-600'],
            ];

            $c = $config[$status];
            return [
                'id' => "{$status}_{$order->id}",
                'type' => 'order',
                'title' => $c['title'],
                'message' => "Pesanan #ORD-" . str_pad($order->id, 5, '0', STR_PAD_LEFT) . " " . ($order->buyer->name ?? 'Pembeli') . " {$c['msg']}.",
                'time' => $order->updated_at->diffForHumans(),
                'raw_time' => $order->updated_at,
                'is_read' => false,
                'icon' => $c['icon'],
                'bg_color' => $c['bg'],
                'text_color' => $c['txt']
            ];
        })->filter();

        // 2. Review Notifications
        $reviews = DB::table('reviews')
            ->join('products', 'reviews.product_id', '=', 'products.id')
            ->join('users', 'reviews.buyer_id', '=', 'users.id')
            ->where('products.seller_id', $sellerId)
            ->select('reviews.*', 'products.name as p_name', 'users.name as b_name')
            ->latest()->take(5)->get()
            ->map(function ($r) {
                if (cache()->has("notif_read_review_{$r->id}")) return null;
                return [
                    'id' => 'review_' . $r->id,
                    'type' => 'review',
                    'title' => 'Ulasan Produk',
                    'message' => "{$r->b_name} memberi rating {$r->rating} pada {$r->p_name}",
                    'time' => \Carbon\Carbon::parse($r->created_at)->diffForHumans(),
                    'raw_time' => $r->created_at,
                    'is_read' => false,
                    'icon' => 'star',
                    'bg_color' => 'bg-green-100',
                    'text_color' => 'text-green-600'
                ];
            })->filter();

        // 3. Chat Notifications (Pesan Belum Dibaca)
        $newChats = DB::table('messages')
            ->join('users', 'messages.sender_id', '=', 'users.id')
            ->where('messages.receiver_id', $sellerId)
            ->where('messages.is_read', 0)
            ->select('messages.*', 'users.name as sender_name')
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($chat) {
                if (cache()->has("notif_read_chat_{$chat->id}")) return null;

                $msgText = $chat->message;
                try { $msgText = decrypt($msgText); } catch (\Exception $e) { }

                return [
                    'id' => 'chat_' . $chat->id,
                    'sender_id' => $chat->sender_id,
                    'type' => 'chat',
                    'title' => 'Pesan Baru',
                    'message' => "Pesan dari {$chat->sender_name}: \"" . \Illuminate\Support\Str::limit($msgText, 40) . "\"",
                    'time' => \Carbon\Carbon::parse($chat->created_at)->diffForHumans(),
                    'raw_time' => $chat->created_at,
                    'is_read' => false,
                    'icon' => 'message-square',
                    'bg_color' => 'bg-purple-100',
                    'text_color' => 'text-purple-600'
                ];
            })->filter();

        // 4. Withdrawal & Stock (Lainnya)
        $others = collect();

        // Gabungkan semua (Orders + Reviews + Chats)
        $all = $orderNotifs->concat($reviews)->concat($newChats);
        
        $sorted = $all->sortByDesc(function($n) {
            return isset($n['raw_time']) ? \Carbon\Carbon::parse($n['raw_time'])->timestamp : 0;
        })->values();

        return ['notifications' => $sorted, 'count' => $sorted->count()];
    }

    public function markNotificationAsRead($id)
    {
        $parts = explode('_', $id);
        $type = $parts[0];
        $realId = $parts[1] ?? null;

        if ($realId) {
            // Use cache to persist the "read" state for 24 hours
            // This avoids DB migration issues while still preventing re-appearance
            cache()->put("notif_read_{$type}_{$realId}", true, now()->addHours(24));
        }

        return response()->json(['success' => true]);
    }
}
