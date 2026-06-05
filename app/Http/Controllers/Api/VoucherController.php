<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user && $user->role === 'admin') {
            // Admin can see all vouchers (including expired and exhausted ones)
            $vouchers = Voucher::latest()->get();
        } else {
            // Regular buyers and sellers can only see active, non-expired, and non-exhausted vouchers
            $vouchers = Voucher::where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->whereColumn('used', '<', 'quota')
                ->latest()
                ->get();
        }
        return response()->json($vouchers);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|unique:vouchers',
                'discount_percent' => 'required|numeric',
                'max_discount' => 'required|numeric',
                'start_date' => 'required',
                'end_date' => 'required',
                'quota' => 'required|numeric'
            ]);

            $voucher = Voucher::create([
                'code' => $request->code,
                'discount_percent' => $request->discount_percent,
                'max_discount' => $request->max_discount,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'quota' => $request->quota,
                'used' => 0
            ]);

            return response()->json([
                'message' => 'Voucher berhasil dibuat',
                'data' => $voucher
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal membuat voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    public function check(Request $request)
    {
        $request->validate([
            'code' => 'required'
        ]);

        $voucher = Voucher::where('code', $request->code)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();

        if (!$voucher) {
            return response()->json([
                'message' => 'Voucher tidak valid atau sudah kadaluarsa'
            ], 400);
        }

        if ($voucher->used >= $voucher->quota) {
            return response()->json([
                'message' => 'Kuota voucher sudah habis'
            ], 400);
        }

        return response()->json($voucher);
    }

    public function destroy($id)
    {
        try {
            $voucher = Voucher::find($id);
            if (!$voucher) {
                return response()->json([
                    'message' => 'Voucher tidak ditemukan'
                ], 404);
            }
            $voucher->delete();
            return response()->json([
                'message' => 'Voucher berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menghapus voucher: ' . $e->getMessage()
            ], 500);
        }
    }
}
