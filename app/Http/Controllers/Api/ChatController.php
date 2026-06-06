<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    // KIRIM PESAN
    public function sendMessage(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required',
            'message' => 'required'
        ]);

        $sender = auth()->user();
        $message = Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $request->receiver_id,
            'message' => $request->message,
            'is_read' => 0
        ]);

        // KIRIM NOTIFIKASI KE PENERIMA
        try {
            $receiver = User::find($request->receiver_id);
            if ($receiver && $receiver->fcm_token) {
                $firebase = new \App\Services\FirebaseService();
                $firebase->sendNotification(
                    $receiver->fcm_token,
                    "Pesan Baru dari " . $sender->name,
                    Str::limit($request->message, 50),
                    [
                        'type' => 'chat',
                        'sender_id' => (string)$sender->id,
                        'message_id' => (string)$message->id
                    ]
                );
            }
        } catch (\Exception $e) {
            \Log::error("Failed to send Chat FCM: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Pesan berhasil dikirim',
            'data' => $message
        ]);
    }

    // LIHAT CHAT
    public function getMessages($userId)
    {
        $messages = Message::where(function ($query) use ($userId) {
            $query->where('sender_id', auth()->id())
                ->where('receiver_id', $userId);
        })
            ->orWhere(function ($query) use ($userId) {
                $query->where('sender_id', $userId)
                    ->where('receiver_id', auth()->id());
            })
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }
    public function getChatList()
    {
        $user = auth()->user();
        $userId = $user->id;

        // Monitoring Mode untuk Admin, Direct Chat Mode untuk User/Seller
        if ($user->role === 'admin') {
            $senderIds = Message::pluck('sender_id')->toArray();
            $receiverIds = Message::pluck('receiver_id')->toArray();
            $contactIds = array_unique(array_merge($senderIds, $receiverIds));
            $contactIds = array_filter($contactIds, fn($id) => $id != $userId);
        } else {
            $senders = Message::where('receiver_id', $userId)->pluck('sender_id')->toArray();
            $receivers = Message::where('sender_id', $userId)->pluck('receiver_id')->toArray();
            $contactIds = array_unique(array_merge($senders, $receivers));
        }

        $contacts = \App\Models\User::whereIn('id', $contactIds)
            ->get()
            ->map(function ($contact) use ($userId, $user) {
                // Ambil pesan terakhir yang melibatkan $userId dan $contact
                $lastMessage = Message::where(function ($q) use ($userId, $contact) {
                    $q->where('sender_id', $userId)->where('receiver_id', $contact->id);
                })
                    ->orWhere(function ($q) use ($userId, $contact) {
                    $q->where('sender_id', $contact->id)->where('receiver_id', $userId);
                })
                    ->latest()
                    ->first();

                // Hitung pesan yang belum dibaca OLEH user yang sedang login ($userId)
                $unreadCount = Message::where('sender_id', $contact->id)
                    ->where('receiver_id', $userId)
                    ->where('is_read', 0)
                    ->count();

                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'role' => $contact->role,
                    'avatar' => $contact->profile_image,
                    'last_message' => $lastMessage->message ?? 'Click to start conversation',
                    'last_message_at' => $lastMessage ? $lastMessage->created_at->toIso8601String() : null,
                    'last_time' => $lastMessage ? $lastMessage->created_at->format('H:i') : '',
                    'unread_count' => $unreadCount
                ];
            });

        return response()->json($contacts);
    }

    public function markAsRead($userId)
    {
        Message::where('sender_id', $userId)
            ->where('receiver_id', auth()->id())
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return response()->json(['message' => 'Pesan ditandai telah dibaca']);
    }

    public function clearChat($userId)
    {
        $authId = auth()->id();
        Message::where(function ($q) use ($authId, $userId) {
            $q->where('sender_id', $authId)->where('receiver_id', $userId);
        })->orWhere(function ($q) use ($authId, $userId) {
            $q->where('sender_id', $userId)->where('receiver_id', $authId);
        })->delete();

        return response()->json(['message' => 'Percakapan berhasil dihapus']);
    }

    public function updateMessage(Request $request, $id)
    {
        $request->validate(['message' => 'required']);
        $message = Message::findOrFail($id);

        if ($message->sender_id != auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Limit edit to 5 minutes
        if ($message->created_at->diffInMinutes(now()) > 5) {
            return response()->json(['message' => 'Waktu edit sudah habis (maks 5 menit)'], 422);
        }

        $message->update(['message' => $request->message]);
        return response()->json(['message' => 'Pesan diperbarui', 'data' => $message]);
    }

    public function deleteMessage($id)
    {
        $message = Message::findOrFail($id);

        if ($message->sender_id != auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Limit delete to 5 minutes
        if ($message->created_at->diffInMinutes(now()) > 5) {
            return response()->json(['message' => 'Waktu hapus sudah habis (maks 5 menit)'], 422);
        }

        $message->delete();
        return response()->json(['message' => 'Pesan dihapus']);
    }

    public function getAdminId()
    {
        $admin = User::where('role', 'admin')->first();
        return response()->json([
            'admin_id' => $admin ? $admin->id : null
        ]);
    }

    public function getUserInfo($id)
    {
        $user = User::findOrFail($id);
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'avatar' => $user->profile_image
        ]);
    }
}
