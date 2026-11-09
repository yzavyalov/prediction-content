<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public User $user;

    public string $name = '';
    public string $email = '';
    public string $role = 'admin';
    public bool $is_active = true;

    public string $password = '';
    public string $password_confirmation = '';

    public function mount(User $user): void
    {
        Gate::authorize('manage-users');

        $this->user = $user;

        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role;
        $this->is_active = $user->is_active;
    }

    public function save()
    {
        Gate::authorize('manage-users');

        $user = User::findOrFail($this->user->id);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role' => ['required', Rule::in(['admin', 'super_admin'])],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
        ]);

        if ($user->id === Auth::id()) {
            if ($data['role'] !== $user->role || ! $data['is_active']) {
                $this->addError('role', 'You cannot change your own role or block yourself.');
                return;
            }
        }

        if (
            $user->isSuperAdmin()
            && $user->is_active
            && (
                $data['role'] !== 'super_admin'
                || ! $data['is_active']
            )
        ) {
            $activeCount = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->count();

            if ($activeCount <= 1) {
                $this->addError('role', 'At least one active Super Admin is required.');
                return;
            }
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        $user->is_active = $data['is_active'];

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return redirect()->route('admin.users.index');
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold">Edit User</h1>
        <p class="text-sm text-slate-500">
            Update administrator account
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

        @error('is_active')
        <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="border-t pt-5">
            <h2 class="mb-4 text-lg font-semibold">Change Password</h2>
            <p class="mb-4 text-sm text-slate-500">
                Leave blank to keep the current password.
            </p>

            <div class="space-y-4">
                <div>
                    <label class="mb-2 block font-medium">New Password</label>
                    <input wire:model="password" type="password"
                           autocomplete="new-password"
                           class="w-full rounded-lg border px-4 py-3">
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-2 block font-medium">Confirm Password</label>
                    <input wire:model="password_confirmation" type="password"
                           autocomplete="new-password"
                           class="w-full rounded-lg border px-4 py-3">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
            <a href="{{ route('admin.users.index') }}"
               class="rounded-lg border px-5 py-3">
                Cancel
            </a>

            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                    class="rounded-lg bg-blue-600 px-6 py-3 font-medium text-white disabled:opacity-60">
                Save Changes
            </button>
        </div>
    </form>
</div>
