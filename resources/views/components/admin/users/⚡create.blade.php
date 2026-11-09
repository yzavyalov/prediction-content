<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $role = 'admin';
    public bool $is_active = true;

    public function save()
    {
        Gate::authorize('manage-users');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'super_admin'])],
            'is_active' => ['required', 'boolean'],
        ]);

        $user = new User();

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->role = $data['role'];
        $user->is_active = $data['is_active'];

        $user->save();

        return redirect()->route('admin.users.index');
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold">Create User</h1>
        <p class="text-sm text-slate-500">
            Create a new administrator account
        </p>
    </div>

    <form wire:submit="save" class="space-y-5 rounded-xl border bg-white p-6 shadow-sm">

        <div>
            <label class="mb-2 block font-medium">Full Name</label>
            <input wire:model="name" type="text"
                   class="w-full rounded-lg border px-4 py-3">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-2 block font-medium">Email</label>
            <input wire:model="email" type="email"
                   class="w-full rounded-lg border px-4 py-3">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-2 block font-medium">Password</label>
            <input wire:model="password" type="password" autocomplete="new-password"
                   class="w-full rounded-lg border px-4 py-3">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-2 block font-medium">Confirm Password</label>
            <input wire:model="password_confirmation" type="password" autocomplete="new-password"
                   class="w-full rounded-lg border px-4 py-3">
        </div>

        <div>
            <label class="mb-2 block font-medium">Role</label>
            <select wire:model="role" class="w-full rounded-lg border px-4 py-3">
                <option value="admin">Admin</option>
                <option value="super_admin">Super Admin</option>
            </select>
            @error('role') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-3">
            <input wire:model="is_active" type="checkbox">
            <span>Active account</span>
        </label>

        <div class="flex justify-end gap-3 pt-4">
            <a href="{{ route('admin.users.index') }}"
               class="rounded-lg border px-5 py-3">
                Cancel
            </a>

            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                    class="rounded-lg bg-blue-600 px-6 py-3 font-medium text-white disabled:opacity-60">
                Create User
            </button>
        </div>
    </form>
</div>
