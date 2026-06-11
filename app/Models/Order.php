<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'seller_id',
        'voucher_id',
        'total_price',
        'discount_amount',
        'final_price',
        'status',
        'shipping_address'
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * Mengembalikan stok produk dan kuota voucher jika pesanan dibatalkan.
     */
    public function restoreStockAndVoucher()
    {
        // 1. Kembalikan stok produk
        foreach ($this->items as $item) {
            if ($item->product) {
                $item->product->increment('stock', $item->quantity);
            }
        }

        // 2. Kembalikan kuota voucher jika ada
        if ($this->voucher_id) {
            $voucher = $this->voucher;
            if ($voucher && $voucher->used > 0) {
                $voucher->decrement('used');
            }
        }
    }
}
