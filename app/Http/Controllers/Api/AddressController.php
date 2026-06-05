<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index()
    {
        $addresses = Address::where('user_id', auth()->id())
            ->orderBy('is_main', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($addresses);
    }

    public function store(Request $request)
    {
        $request->validate([
            'label' => 'required|string|max:255',
            'receiver_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
            'full_address' => 'required|string',
            'is_main' => 'boolean'
        ]);

        $userId = auth()->id();

        // If this is the first address, make it main
        $count = Address::where('user_id', $userId)->count();
        $isMain = $count === 0 ? true : ($request->is_main ?? false);

        if ($isMain) {
            Address::where('user_id', $userId)->update(['is_main' => false]);
        }

        $address = Address::create([
            'user_id' => $userId,
            'label' => $request->label,
            'receiver_name' => $request->receiver_name,
            'phone_number' => $request->phone_number,
            'full_address' => $request->full_address,
            'is_main' => $isMain
        ]);

        return response()->json([
            'message' => 'Address added successfully',
            'address' => $address
        ]);
    }

    public function update(Request $request, $id)
    {
        $address = Address::where('user_id', auth()->id())->findOrFail($id);

        $request->validate([
            'label' => 'string|max:255',
            'receiver_name' => 'string|max:255',
            'phone_number' => 'string|max:20',
            'full_address' => 'string',
            'is_main' => 'boolean'
        ]);

        if ($request->has('is_main') && $request->is_main == true) {
            Address::where('user_id', auth()->id())->update(['is_main' => false]);
        }

        $address->update($request->all());

        return response()->json([
            'message' => 'Address updated successfully',
            'address' => $address
        ]);
    }

    public function destroy($id)
    {
        $address = Address::where('user_id', auth()->id())->findOrFail($id);

        $wasMain = $address->is_main;
        $address->delete();

        // If deleted was main and there are other addresses, set one as main
        if ($wasMain) {
            $next = Address::where('user_id', auth()->id())->first();
            if ($next) {
                $next->update(['is_main' => true]);
            }
        }

        return response()->json(['message' => 'Address deleted successfully']);
    }

    public function setMain($id)
    {
        Address::where('user_id', auth()->id())->update(['is_main' => false]);

        $address = Address::where('user_id', auth()->id())->findOrFail($id);
        $address->update(['is_main' => true]);

        return response()->json([
            'message' => 'Main address updated',
            'address' => $address
        ]);
    }
}
