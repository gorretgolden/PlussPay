<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::with('creator');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $customers = $query->latest()->paginate(20);

        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        return view('customers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|min:2',
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'type' => 'required|in:sender,receiver,both',
            'country' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
        ], [
            'name.required' => 'Customer name is required.',
            'name.min' => 'Name must be at least 2 characters.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        Customer::create([
            'name' => trim($validated['name']),
            'phone' => trim($validated['phone']),
            'type' => $validated['type'],
            'country' => !empty($validated['country']) ? trim($validated['country']) : 'Uganda',
            'notes' => !empty($validated['notes']) ? trim($validated['notes']) : null,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('customers.index')->with('success', 'Customer added successfully.');
    }

    public function show(Customer $customer)
    {
        $customer->load('creator');
        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer)
    {
        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|min:2',
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'type' => 'required|in:sender,receiver,both',
            'country' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Customer name is required.',
            'name.min' => 'Name must be at least 2 characters.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        $customer->update([
            'name' => trim($validated['name']),
            'phone' => trim($validated['phone']),
            'type' => $validated['type'],
            'country' => !empty($validated['country']) ? trim($validated['country']) : 'Uganda',
            'notes' => !empty($validated['notes']) ? trim($validated['notes']) : null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->route('customers.index')->with('success', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return back()->with('success', 'Customer deleted.');
    }
}
