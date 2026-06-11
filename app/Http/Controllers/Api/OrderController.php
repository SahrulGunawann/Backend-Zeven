<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    // BUYER LIHAT ORDER
    public function myOrders()
    {
        $orders = Order::with(['items.product', 'transaction'])
            ->where('buyer_id', auth()->id())
            ->latest()
            ->get();

        return response()->json($orders);
    }

    // LIHAT DETAIL ORDER
    public function show($id)
    {
        // Bersihkan ID jika ada karakter non-angka (seperti #ORD-)
        $cleanId = preg_replace('/[^0-9]/', '', $id);
        
        $order = Order::with(['items.product', 'seller', 'buyer', 'transaction'])
            ->where('id', $cleanId)
            ->firstOrFail();

        // Pastikan yang melihat adalah pemilik, penjualnya, atau admin
        $user = auth()->user();
        if ($order->buyer_id != $user->id && $order->seller_id != $user->id && $user->role !== 'admin') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        return response()->json($order);
    }

    public function sellerOrders(Request $request)
    {
        $search = $request->query('search');
        $status = $request->query('status');
        $cleanSearch = str_replace('#', '', $search);
        $numericSearch = ltrim($cleanSearch, '0');

        $orders = Order::with(['items.product', 'buyer'])
            ->where('seller_id', auth()->id())
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($search, function ($query) use ($cleanSearch, $numericSearch) {
                $query->where(function ($q) use ($cleanSearch, $numericSearch) {
                    $q->where('id', 'like', "%$cleanSearch%")
                        ->orWhere('id', 'like', "%$numericSearch%")
                        ->orWhereHas('buyer', function ($bq) use ($cleanSearch) {
                            $bq->where('name', 'like', "%$cleanSearch%");
                        })
                        ->orWhereHas('items.product', function ($pq) use ($cleanSearch) {
                            $pq->where('name', 'like', "%$cleanSearch%");
                        });
                });
            })
            ->latest()
            ->paginate(10);

        return response()->json($orders);
    }

    // UPDATE STATUS ORDER
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processed,shipped,completed,canceled'
        ]);

        $order = Order::with('buyer')->findOrFail($id);

        if ($order->seller_id != auth()->id()) {
            return response()->json([
                'message' => 'Akses ditolak'
            ], 403);
        }

        $oldStatus = $order->status;
        $order->status = $request->status;
        $order->save();

        // Jika dibatalkan, kembalikan stok dan voucher
        if ($order->status === 'canceled' && $oldStatus !== 'canceled') {
            $order->restoreStockAndVoucher();
        }

        // KIRIM NOTIFIKASI FCM KE BUYER JIKA STATUS BERUBAH JADI DIKIRIM (SHIPPED)
        if ($order->status === 'shipped' && $oldStatus !== 'shipped') {
            try {
                $firebase = new FirebaseService();
                $buyer = $order->buyer;
                if ($buyer && $buyer->fcm_token) {
                    $firebase->sendNotification(
                        $buyer->fcm_token,
                        'Pesanan Dikirim!',
                        "Paket #ORD-" . str_pad($order->id, 5, '0', STR_PAD_LEFT) . " sedang dalam perjalanan ke alamat Anda.",
                        ['order_id' => (string)$order->id, 'type' => 'order_shipped']
                    );
                }
            } catch (\Exception $e) {
                Log::error("Failed to send FCM: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Status order berhasil diupdate',
            'order' => $order
        ]);
    }

    // BUYER MENYELESAIKAN ORDER (PESANAN DITERIMA)
    public function completeOrder($id)
    {
        $order = Order::with(['seller', 'buyer'])->findOrFail($id);

        if ($order->buyer_id != auth()->id()) {
            return response()->json([
                'message' => 'Akses ditolak'
            ], 403);
        }

        if ($order->status !== 'shipped') {
            return response()->json([
                'message' => 'Hanya pesanan yang sedang dikirim yang dapat dikonfirmasi diterima'
            ], 400);
        }

        $order->status = 'completed';
        $order->save();

        // KIRIM NOTIFIKASI FCM KE SELLER JIKA PESANAN SELESAI (DITERIMA BUYER)
        try {
            $firebase = new FirebaseService();
            $seller = $order->seller;
            if ($seller && $seller->fcm_token) {
                $firebase->sendNotification(
                    $seller->fcm_token,
                    'Pesanan Selesai!',
                    "Pembeli " . ($order->buyer->name ?? '') . " telah menerima paket #ORD-" . str_pad($order->id, 5, '0', STR_PAD_LEFT) . ".",
                    ['order_id' => (string)$order->id, 'type' => 'order_completed']
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to send FCM: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Pesanan berhasil dikonfirmasi diterima',
            'order' => $order
        ]);
    }

    // BUYER MEMBATALKAN ORDER
    public function cancelOrder($id)
    {
        $order = Order::with('transaction')->findOrFail($id);

        if ($order->buyer_id != auth()->id()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        // Hanya bisa batal jika status masih pending
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Pesanan sudah diproses dan tidak dapat dibatalkan'], 400);
        }

        // Coba batalkan di DompetX juga
        try {
            $paymentController = app(PaymentController::class);
            $cancelledAtDompetX = $paymentController->cancelCheckout($order->id);
            \Log::info("Cancellation for Order #{$order->id}: DompetX sync " . ($cancelledAtDompetX ? "SUCCESS" : "FAILED/SKIPPED"));
        } catch (\Exception $e) {
            \Log::error("DompetX Cancellation Error for Order #{$order->id}: " . $e->getMessage());
        }

        $order->status = 'canceled';
        $order->save();

        // Kembalikan stok dan voucher
        $order->restoreStockAndVoucher();

        if ($order->transaction) {
            $order->transaction->update(['payment_status' => 'failed']);
        }

        return response()->json([
            'message' => 'Pesanan berhasil dibatalkan',
            'order' => $order
        ]);
    }

    private function restoreStockAndVoucher(Order $order)
    {
        $order->restoreStockAndVoucher();
    }
}
