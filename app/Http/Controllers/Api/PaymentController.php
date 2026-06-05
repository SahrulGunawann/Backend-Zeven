<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    private function getDompetXConfig()
    {
        return [
            'api_key' => config('services.dompetx.api_key'),
            'base_url' => 'https://api.dompetx.com/v1',
        ];
    }

    public function getToken(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_type' => 'nullable|string' // permata, mandiri, qris, etc
        ]);

        $order = Order::with('buyer')->findOrFail($request->order_id);

        if ($order->buyer_id !== auth()->id()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $dompetxOrderId = 'ZVN-' . $order->id . '-' . time();
        
        // Simpan method sementara (ID Ref)
        $initialMethod = $request->payment_type ? strtoupper($request->payment_type) : $dompetxOrderId;
        
        Transaction::updateOrCreate(
            ['order_id' => $order->id],
            [
                'payment_method' => $initialMethod,
                'payment_status' => 'pending'
            ]
        );

        $config = $this->getDompetXConfig();
        $timestamp = time();
        
        $bodyData = [
            'amount' => (int) $order->final_price,
            'currency' => 'IDR',
            'reference' => $dompetxOrderId,
            'metadata' => [
                'customer_name' => $order->buyer->name,
                'customer_email' => $order->buyer->email,
            ]
        ];

        // JIKA USER SUDAH PILIH METODE DI APP, KITA TETAP KIRIM TYPE SEBAGAI INFORMASI KE DOMPETX
        if ($request->payment_type) {
            $bodyData['type'] = $request->payment_type;
        }

        $jsonBody = json_encode($bodyData);
        
        // Generate Signature
        $signatureData = $timestamp . '.' . $jsonBody;
        $signature = hash_hmac('sha256', $signatureData, $config['api_key']);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-DOMPAY-API-Key' => $config['api_key'],
                'X-DOMPAY-Signature' => $signature,
                'X-DOMPAY-Timestamp' => $timestamp,
                'Idempotency-Key' => 'req_' . Str::random(10) . '_' . $timestamp,
            ])->post($config['base_url'] . '/payments/checkout', $bodyData);

            if ($response->successful()) {
                $data = $response->json();
                $paymentUrl = $data['payment_url'] ?? $data['payment_link'] ?? $data['paymentUrl'] ?? null;
                $dompetxId = $data['id'] ?? null;

                if (!$paymentUrl) {
                    return response()->json(['message' => 'Gagal mendapatkan link checkout dari DompetX'], 500);
                }

                if ($dompetxId) {
                    Transaction::where('order_id', $order->id)->update([
                        'payment_method' => $dompetxOrderId . '|' . $dompetxId
                    ]);
                }

                return response()->json([
                    'payment_url' => $paymentUrl,
                    'redirect_url' => $paymentUrl,
                    'token' => $dompetxId,
                    'dompetx_order_id' => $dompetxOrderId
                ]);
            }

            Log::error('DompetX Error Response: ' . $response->body());
            return response()->json([
                'message' => 'Gagal membuat pembayaran di DompetX',
                'details' => $response->json()
            ], 500);

        } catch (\Exception $e) {
            Log::error('DompetX Exception: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan sistem', 'error' => $e->getMessage()], 500);
        }
    }

    public function notification(Request $request)
    {
        // 1. Ambil Header Keamanan dari DompetX
        $signatureHeader = $request->header('X-DOMPAY-Signature');
        $timestampHeader = $request->header('X-DOMPAY-Timestamp');
        
        // 2. Ambil Raw Body (data mentah)
        $rawBody = $request->getContent();
        
        // 3. Verifikasi Signature (Hanya jika bukan di lokal/testing tertentu)
        // Jika Anda ingin tetap bisa nembak lewat Postman saat dev, bisa tambahkan check APP_ENV
        if (config('app.env') === 'production' || $signatureHeader) {
            $config = $this->getDompetXConfig();
            $expectedSignature = hash_hmac('sha256', $timestampHeader . '.' . $rawBody, $config['api_key']);
            
            if ($signatureHeader !== $expectedSignature) {
                Log::warning('DompetX Security: Invalid Signature Attempt!', [
                    'received' => $signatureHeader,
                    'expected' => $expectedSignature
                ]);
                return response()->json(['message' => 'Unauthorized Signature'], 401);
            }
        }

        // 4. Proses data jika signature valid
        $payload = $request->all();

        // Berdasarkan dokumen, data ada di dalam object 'data'
        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? $payload['reference'] ?? '';
        $status = strtoupper($data['status'] ?? $payload['status'] ?? '');

        if (!$reference) {
            return response()->json(['message' => 'Reference not found'], 400);
        }

        $parts = explode('-', $reference);
        if (count($parts) < 2 || $parts[0] !== 'ZVN') {
            return response()->json(['message' => 'Invalid Ref Format'], 400);
        }

        $order = Order::find($parts[1]);
        if (!$order) return response()->json(['message' => 'Order not found'], 404);

        // KEAMANAN TAMBAHAN: Jangan proses jika pesanan sudah dibatalkan sebelumnya
        if ($order->status === 'canceled') {
            Log::warning("DompetX: Payment received for ALREADY CANCELED order #{$order->id}");
            return response()->json(['message' => 'Order already canceled'], 200); // Beri 200 agar DompetX tidak kirim ulang
        }

        $paymentStatus = 'pending';
        $orderStatus = $order->status;
        $actualMethod = $data['type'] ?? $payload['type'] ?? null; // Menangkap metode spesifik (misal: qr_dynamic, va, dll)

        if ($status === 'SUCCESS' || $status === 'PAID' || $status === 'COMPLETED') {
            $paymentStatus = 'paid';
            $orderStatus = 'processed'; // Otomatis ke 'Diproses' jika bayar sukses
        } elseif (in_array($status, ['FAILED', 'EXPIRED', 'CANCELLED', 'CANCELED'])) {
            $paymentStatus = 'failed';
            $orderStatus = 'canceled';
        }

        $transaction = Transaction::where('order_id', $order->id)->first();
        if ($transaction) {
            $updateData = [
                'payment_status' => $paymentStatus,
                'paid_at' => $paymentStatus === 'paid' ? now() : null
            ];

            // Simpan metode spesifik (misal: VA_PERMATA)
            if ($actualMethod) {
                $updateData['payment_method'] = strtoupper(str_replace('_', ' ', $actualMethod));
            }

            $transaction->update($updateData);
        }

        // Update status order berdasarkan hasil pembayaran
        $order->update(['status' => $orderStatus]);

        // KIRIM NOTIFIKASI FCM KE BUYER JIKA PEMBAYARAN SUKSES
        if ($paymentStatus === 'paid') {
            try {
                $firebase = new FirebaseService();
                $buyer = $order->buyer;
                if ($buyer && $buyer->fcm_token) {
                    $firebase->sendNotification(
                        $buyer->fcm_token,
                        'Pembayaran Berhasil!',
                        "Pesanan #ORD-" . str_pad($order->id, 5, '0', STR_PAD_LEFT) . " Anda telah dibayar. Penjual akan segera memproses pesanan Anda.",
                        ['order_id' => (string)$order->id, 'type' => 'payment_success']
                    );
                }
            } catch (\Exception $e) {
                Log::error("Failed to send FCM: " . $e->getMessage());
            }
        }

        return response()->json(['message' => 'OK']);
    }

    public function checkStatus(Request $request, $orderId)
    {
        $order = Order::findOrFail($orderId);
        $transaction = Transaction::where('order_id', $order->id)->first();

        if ($transaction && !empty($transaction->payment_method)) {
            // Parse UUID dari payment_method
            $raw = $transaction->payment_method;
            $dompetxId = null;
            if (str_contains($raw, '|')) {
                $dompetxId = explode('|', $raw)[1] ?? null;
            } elseif (str_contains($raw, ':')) {
                $dompetxId = explode(':', $raw)[1] ?? null;
            }

            if ($dompetxId) {
                try {
                    $config = $this->getDompetXConfig();
                    $response = Http::withHeaders([
                        'X-DOMPAY-API-Key' => $config['api_key'],
                    ])->get($config['base_url'] . '/payments/checkout/' . $dompetxId);

                    if ($response->successful()) {
                        $data = $response->json();
                        $status = strtoupper($data['status'] ?? '');
                        $actualMethod = $data['type'] ?? null;

                        $updateData = [];
                        if ($status === 'SUCCESS' || $status === 'PAID') {
                            $updateData['payment_status'] = 'paid';
                            $order->update(['status' => 'processed']);
                        }

                        if ($actualMethod) {
                            // Update nama bank di DB lokal
                            $updateData['payment_method'] = strtoupper(str_replace('_', ' ', $actualMethod));
                        }

                        if (!empty($updateData)) {
                            $transaction->update($updateData);
                        }
                    }
                } catch (\Exception $e) {
                }
            }
        }

        return response()->json([
            'payment_status' => $transaction ? $transaction->payment_status : 'pending',
            'order_status' => $order->status,
            'payment_method' => $transaction ? $transaction->payment_method : null
        ]);
    }

    public function cancelCheckout($orderId)
    {
        $transaction = Transaction::where('order_id', $orderId)->first();
        
        if (!$transaction || empty($transaction->payment_method)) {
            return false;
        }

        // Parse format baru: ZVN-ID-TIME|UUID atau format lama
        $raw = $transaction->payment_method;
        $dompetxId = null;

        if (str_contains($raw, '|')) {
            $dompetxId = explode('|', $raw)[1] ?? null;
        } elseif (str_contains($raw, ':')) {
            $dompetxId = explode(':', $raw)[1] ?? null;
        }
        
        if (!$dompetxId || strlen($dompetxId) < 10) {
            Log::error("DompetX Cancel Error: No valid UUID found for Order #{$orderId}");
            return false;
        }

        $config = $this->getDompetXConfig();
        $timestamp = time();
        $bodyData = ['id' => $dompetxId]; 
        $jsonBody = json_encode($bodyData);
        
        $signature = hash_hmac('sha256', $timestamp . '.' . $jsonBody, $config['api_key']);

        try {
            // Kita coba batalkan menggunakan endpoint checkout (utama)
            $url = $config['base_url'] . '/payments/checkout/cancel/' . $dompetxId;
            Log::info("DompetX Cancel Attempt: {$url}");

            $res = Http::withHeaders([
                'X-DOMPAY-API-Key' => $config['api_key'],
                'X-DOMPAY-Signature' => hash_hmac('sha256', $timestamp . '.' . $jsonBody, $config['api_key']),
                'X-DOMPAY-Timestamp' => $timestamp,
            ])->post($url, $bodyData);
            
            // Jika gagal di checkout, coba endpoint payment/cancel (untuk yang sudah punya tipe VA/QRIS)
            if (!$res->successful()) {
                $altUrl = $config['base_url'] . '/payments/cancel/' . $dompetxId;
                Log::info("DompetX Alt Cancel Attempt: {$altUrl}");
                $res = Http::withHeaders([
                    'X-DOMPAY-API-Key' => $config['api_key'],
                    'X-DOMPAY-Signature' => hash_hmac('sha256', $timestamp . '.' . $jsonBody, $config['api_key']),
                    'X-DOMPAY-Timestamp' => $timestamp,
                ])->post($altUrl, $bodyData);
            }
            
            if ($res->successful()) {
                Log::info("DompetX Cancel SUCCESS for Order #{$orderId}");
                return true;
            } else {
                Log::error("DompetX Cancel FAILED for Order #{$orderId}. Final Response: " . $res->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("DompetX Cancel Exception for Order #{$orderId}: " . $e->getMessage());
            return false;
        }
    }
}
