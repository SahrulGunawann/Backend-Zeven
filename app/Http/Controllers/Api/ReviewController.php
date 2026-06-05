<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    // TAMBAH REVIEW
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'order_id' => 'required',
            'rating' => 'required|numeric|min:1|max:5',
            'review' => 'nullable'
        ]);

        // cek order completed
        $order = Order::findOrFail($request->order_id);

        if ($order->status != 'completed') {
            return response()->json([
                'message' => 'Order belum selesai'
            ], 400);
        }

        // cek sudah review atau belum
        $existingReview = Review::where('buyer_id', auth()->id())
            ->where('product_id', $request->product_id)
            ->where('order_id', $request->order_id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'message' => 'Produk sudah direview'
            ], 400);
        }

        $review = Review::create([
            'buyer_id' => auth()->id(),
            'product_id' => $request->product_id,
            'order_id' => $request->order_id,
            'rating' => $request->rating,
            'review' => $request->review
        ]);

        return response()->json([
            'message' => 'Review berhasil ditambahkan',
            'data' => $review
        ]);
    }

    // LIHAT REVIEW PRODUCT
    public function productReviews($productId)
    {
        $reviews = Review::with('buyer')
            ->where('product_id', $productId)
            ->get();

        // rata-rata rating
        $averageRating = Review::where('product_id', $productId)
            ->avg('rating');

        return response()->json([
            'average_rating' => round($averageRating, 1),
            'reviews' => $reviews
        ]);
    }
    public function sellerReviews()
    {
        $baseQuery = Review::whereHas('product', function ($query) {
            $query->where('seller_id', auth()->id());
        });

        // Hitung statistik keseluruhan ulasan secara global
        $totalReviews = (clone $baseQuery)->count();
        $fiveStarCount = (clone $baseQuery)->where('rating', 5)->count();
        $averageRating = (clone $baseQuery)->avg('rating');

        $ratingCounts = [
            'total' => $totalReviews,
            'five_star' => $fiveStarCount,
            'average' => round($averageRating ?? 0, 1),
            'distribution' => [
                5 => (clone $baseQuery)->where('rating', 5)->count(),
                4 => (clone $baseQuery)->where('rating', 4)->count(),
                3 => (clone $baseQuery)->where('rating', 3)->count(),
                2 => (clone $baseQuery)->where('rating', 2)->count(),
                1 => (clone $baseQuery)->where('rating', 1)->count(),
            ]
        ];

        // Lakukan paginasi ulasan (5 data per halaman)
        $reviews = Review::with(['buyer', 'product'])
            ->whereHas('product', function ($query) {
                $query->where('seller_id', auth()->id());
            })
            ->latest()
            ->paginate(5);

        return response()->json([
            'stats' => $ratingCounts,
            'reviews' => $reviews
        ]);
    }

    public function reply(Request $request, $id)
    {
        $request->validate([
            'reply' => 'required|string'
        ]);

        $review = Review::findOrFail($id);

        // Cek apakah produk ini milik seller yang sedang login
        if ($review->product->seller_id != auth()->id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $review->update([
            'reply' => $request->reply
        ]);

        return response()->json([
            'message' => 'Berhasil membalas ulasan',
            'data' => $review
        ]);
    }

    public function buyerRepliedReviews()
    {
        $reviews = Review::with(['product', 'product.seller'])
            ->where('buyer_id', auth()->id())
            ->whereNotNull('reply')
            ->where('reply', '!=', '')
            ->latest()
            ->get();

        return response()->json($reviews);
    }
}
