@extends('layouts.app')

@section('title', 'Create User')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Create User</h1>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl mx-auto">
    <form method="POST" action="{{ route('users.store') }}">
        @csrf

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
            <p class="text-xs text-gray-500 mt-1">First name and last name, like Gorret Golden. We correct the capital letters for you.</p>
            @error('name')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
            @error('email')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
            <input type="text" name="phone" value="{{ old('phone') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
            <p class="text-xs text-gray-500 mt-1">Each account needs its own number. Duplicated country codes and leading zeros are corrected automatically.</p>
            @error('phone')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <div class="relative">
                <input id="password" type="password" name="password" required minlength="8"
                    class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
                <button type="button" onclick="togglePassword('password', this)" aria-label="Show password"
                    class="absolute inset-y-0 right-2 flex items-center text-gray-400 hover:text-gray-600">
                    <svg class="eye-open w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <svg class="eye-closed w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18M10.587 10.587a2 2 0 002.828 2.828M15.966 16.156A9.023 9.023 0 0112 17c-4.478 0-8.268-2.943-9.542-7a11.92 11.92 0 013.163-4.446M5.83 5.83A11.896 11.896 0 0112 5c4.478 0 8.268 2.943 9.542 7a11.95 11.95 0 01-1.926 2.94"/>
                    </svg>
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
            <div class="relative">
                <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8"
                    class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
                <button type="button" onclick="togglePassword('password_confirmation', this)" aria-label="Show password"
                    class="absolute inset-y-0 right-2 flex items-center text-gray-400 hover:text-gray-600">
                    <svg class="eye-open w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <svg class="eye-closed w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18M10.587 10.587a2 2 0 002.828 2.828M15.966 16.156A9.023 9.023 0 0112 17c-4.478 0-8.268-2.943-9.542-7a11.92 11.92 0 013.163-4.446M5.83 5.83A11.896 11.896 0 0112 5c4.478 0 8.268 2.943 9.542 7a11.95 11.95 0 01-1.926 2.94"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
            <div class="relative" id="role-picker">
                <input type="text" id="role-search" autocomplete="off" placeholder="Search or select role"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
                <input type="hidden" name="role" id="role-value" required>
                <span class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </span>
                <ul id="role-list"
                    class="absolute z-10 mt-1 w-full max-h-52 overflow-y-auto bg-white border border-gray-300 rounded-md shadow-lg hidden">
                    @foreach($roles as $role)
                    <li class="role-option px-3 py-2 text-sm hover:bg-green-50 cursor-pointer" data-value="{{ $role->name }}">{{ $role->name }}</li>
                    @endforeach
                </ul>
                <p id="role-empty" class="px-3 py-2 text-sm text-gray-500 hidden">No matching role</p>
            </div>
        </div>

        @include('users.partials.access-fields', [
            'selectedDays' => old('days', []),
            'allowedFrom' => old('allowed_from'),
            'allowedTo' => old('allowed_to'),
        ])

        @include('users.partials.country-fields')

        <div class="flex gap-4">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Create User</button>
            <a href="{{ route('users.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    function togglePassword(id, btn) {
        const input = document.getElementById(id);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.querySelector('.eye-open').classList.toggle('hidden', show);
        btn.querySelector('.eye-closed').classList.toggle('hidden', !show);
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }

    (function () {
        const picker = document.getElementById('role-picker');
        const search = document.getElementById('role-search');
        const hidden = document.getElementById('role-value');
        const list = document.getElementById('role-list');
        const empty = document.getElementById('role-empty');
        const options = Array.from(picker.querySelectorAll('.role-option'));

        function open() { list.classList.remove('hidden'); filter(); }
        function close() { list.classList.add('hidden'); }
        function filter() {
            const q = search.value.trim().toLowerCase();
            let visible = 0;
            options.forEach(li => {
                const match = li.dataset.value.toLowerCase().includes(q);
                li.classList.toggle('hidden', !match);
                if (match) visible++;
            });
            empty.classList.toggle('hidden', visible !== 0);
        }

        search.addEventListener('focus', open);
        search.addEventListener('input', () => { hidden.value = ''; open(); });
        options.forEach(li => li.addEventListener('click', () => {
            search.value = li.dataset.value;
            hidden.value = li.dataset.value;
            close();
        }));
        document.addEventListener('click', e => { if (!picker.contains(e.target)) close(); });
        picker.closest('form').addEventListener('submit', e => { if (!hidden.value) { e.preventDefault(); search.focus(); } });

        @if(old('role'))
        search.value = @json(old('role'));
        hidden.value = @json(old('role'));
        @endif
    })();
</script>
@endpush
