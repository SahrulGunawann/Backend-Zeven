<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    private $credentialsPath;
    private $projectId;

    public function __construct()
    {
        $this->credentialsPath = storage_path('app/zeven-marketplace-firebase-adminsdk-fbsvc-c3dc6f855c.json');
        
        if (file_exists($this->credentialsPath)) {
            $credentials = json_decode(file_get_contents($this->credentialsPath), true);
            $this->projectId = $credentials['project_id'];
        }
    }

    /**
     * Mendapatkan Access Token OAuth2 secara manual (Google Auth)
     */
    private function getAccessToken()
    {
        if (!file_exists($this->credentialsPath)) {
            Log::error('Firebase Credentials file not found at: ' . $this->credentialsPath);
            return null;
        }

        $credentials = json_decode(file_get_contents($this->credentialsPath), true);
        
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $payload = json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = '';
        openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $credentials['private_key'], 'sha256');
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]);

        return $response->json()['access_token'] ?? null;
    }

    public function sendNotification($token, $title, $body, $data = [])
    {
        $accessToken = $this->getAccessToken();
        
        if (!$accessToken || !$token) {
            return false;
        }

        // Format Payload untuk API V1
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => collect($data)->map(fn($val) => (string)$val)->toArray(),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", $payload);

            if ($response->successful()) {
                return true;
            }

            Log::error('FCM V1 Error: ' . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error('FCM V1 Exception: ' . $e->getMessage());
            return false;
        }
    }
}
