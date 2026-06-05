<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    private $serverKey;

    public function __construct()
    {
        // Gunakan server key dari .env atau config
        $this->serverKey = config('services.firebase.server_key');
    }

    /**
     * Kirim notifikasi ke spesifik token (FCM)
     */
    public function sendNotification($token, $title, $body, $data = [])
    {
        if (!$token) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->serverKey,
                'Content-Type' => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                ],
                'data' => array_merge([
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ], $data),
                'priority' => 'high',
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('FCM Error: ' . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error('FCM Exception: ' . $e->getMessage());
            return false;
        }
    }
}
