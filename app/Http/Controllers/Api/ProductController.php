<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    // GET ALL PRODUCTS
    public function index(Request $request)
    {
        $query = Product::with('seller');

        // category filter
        if ($request->category) {
            $query->where('category', $request->category);
        }

        // seller filter
        if ($request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        // search
        if ($request->search) {
            $query->where('name', 'LIKE', '%' . $request->search . '%');
        }

        // min price
        if ($request->min_price) {
            $query->where('price', '>=', $request->min_price);
        }

        // max price
        if ($request->max_price) {
            $query->where('price', '<=', $request->max_price);
        }

        // sorting
        if ($request->sort_by === 'cheapest') {
            $query->orderBy('price', 'asc');
        } elseif ($request->sort_by === 'expensive') {
            $query->orderBy('price', 'desc');
        } else {
            $query->latest();
        }

        // pagination
        $products = $query->paginate(12);

        return response()->json($products);
    }

    // SELLER PRODUCTS
    public function sellerProducts(Request $request)
    {
        $sellerId = auth()->id();
        $search = $request->query('search');
        $category = $request->query('category');
        $status = $request->query('status');

        // 1. Ambil daftar kategori produk seller secara unik dan global
        $categories = Product::where('seller_id', $sellerId)
            ->pluck('category')
            ->unique()
            ->filter()
            ->values()
            ->all();

        // 2. Lakukan kueri produk terpaginasi (10 data per halaman)
        $query = Product::where('seller_id', $sellerId)
            ->when($search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($category, function ($query, $category) {
                $query->where('category', $category);
            })
            ->when($status, function ($query, $status) {
                if ($status === 'active') {
                    $query->where('stock', '>', 0);
                }

                if ($status === 'pending') {
                    $query->where('stock', '<=', 0);
                }
            })
            ->latest();

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedProducts */
        $paginatedProducts = $query->paginate(10);

        // Ambil penjualan hanya untuk produk yang tampil di halaman aktif ini
        $productIds = collect($paginatedProducts->items())->pluck('id');

        $salesByProduct = OrderItem::selectRaw('product_id, SUM(quantity) as total_quantity')
            ->whereIn('product_id', $productIds)
            ->whereHas('order', function ($query) use ($sellerId) {
                $query->where('seller_id', $sellerId)
                    ->where('status', 'completed');
            })
            ->groupBy('product_id')
            ->pluck('total_quantity', 'product_id');

        // Gunakan through() untuk memetakan item di dalam paginator secara elegan (Ramah IDE & Menghilangkan Warning)
        $paginatedProducts->through(function ($product) use ($salesByProduct) {
            $sales = (int) ($salesByProduct[$product->id] ?? 0);
            $status = $product->stock > 0 ? 'active' : 'pending';

            return [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category ?? 'General',
                'price' => 'Rp ' . number_format($product->price ?? 0, 0, ',', '.'),
                'stock' => $product->stock,
                'sales' => $sales,
                'status' => $status,
                'image' => $product->image_url
            ];
        });

        return response()->json([
            'products' => $paginatedProducts,
            'categories' => $categories
        ]);
    }

    // CREATE PRODUCT
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'category' => 'nullable|string|max:255',
            'price' => 'required|numeric',
            'stock' => 'required|numeric',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'additional_images.*' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048'
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::create([
            'seller_id' => auth()->id(),
            'name' => $request->name,
            'category' => $request->category,
            'description' => $request->description,
            'price' => $request->price,
            'stock' => $request->stock,
            'image' => $imagePath
        ]);

        // Primary image in gallery
        if ($imagePath) {
            $product->images()->create([
                'image_path' => $imagePath,
                'is_primary' => true
            ]);
        }

        // Additional images
        if ($request->hasFile('additional_images')) {
            foreach ($request->file('additional_images') as $file) {
                $path = $file->store('products', 'public');
                $product->images()->create(['image_path' => $path]);
            }
        }

        return response()->json([
            'message' => 'Product berhasil ditambahkan',
            'data' => $product->load('images')
        ]);
    }

    // DETAIL PRODUCT
    public function show($id)
    {
        $product = Product::with(['seller', 'images'])
            ->withCount([
                'orderItems as total_sales' => function ($query) {
                    $query->whereHas('order', function ($q) {
                        $q->whereIn('status', ['processed', 'shipped', 'completed']);
                    });
                }
            ])
            ->findOrFail($id);

        return response()->json($product);
    }

    // UPDATE PRODUCT
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        // ownership validation
        if ($product->seller_id != auth()->id()) {

            return response()->json([
                'message' => 'Akses ditolak'
            ], 403);
        }

        $request->validate([
            'name' => 'required',
            'category' => 'nullable|string|max:255',
            'price' => 'required|numeric',
            'stock' => 'required|numeric',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'additional_images.*' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'delete_images' => 'nullable|string'
        ]);

        if ($request->hasFile('image')) {
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $imagePath = $request->file('image')->store('products', 'public');
            $product->image = $imagePath;

            // Sync primary gallery image
            $primary = $product->images()->where('is_primary', true)->first();
            if ($primary) {
                $primary->update(['image_path' => $imagePath]);
            } else {
                $product->images()->create(['image_path' => $imagePath, 'is_primary' => true]);
            }
        }

        if ($request->delete_images) {
            $ids = json_decode($request->delete_images, true);
            if (is_array($ids)) {
                foreach ($product->images()->whereIn('id', $ids)->get() as $img) {
                    if (!$img->is_primary) { // Don't delete primary here unless updated
                        if (Storage::disk('public')->exists($img->image_path)) {
                            Storage::disk('public')->delete($img->image_path);
                        }
                        $img->delete();
                    }
                }
            }
        }

        if ($request->hasFile('additional_images')) {
            foreach ($request->file('additional_images') as $file) {
                $path = $file->store('products', 'public');
                $product->images()->create(['image_path' => $path]);
            }
        }

        $product->name = $request->name ?? $product->name;
        $product->category = $request->category ?? $product->category;
        $product->description = $request->description ?? $product->description;
        $product->price = $request->price ?? $product->price;
        $product->stock = $request->stock ?? $product->stock;
        $product->save();

        return response()->json([
            'message' => 'Produk berhasil diperbarui',
            'data' => $product->load('images')
        ]);
    }

    // DELETE PRODUCT
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        if ($product->seller_id != auth()->id()) {

            return response()->json([
                'message' => 'Akses ditolak'
            ], 403);
        }

        // hapus image
        if (
            $product->image &&
            Storage::disk('public')->exists($product->image)
        ) {

            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product berhasil dihapus'
        ]);
    }
}
