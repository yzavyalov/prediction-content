<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $deleteUserId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function users()
    {
        return User::query()
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('email', 'like', '%' . $this->search . '%');
                });
            })
            ->orderBy('id')
            ->paginate(15);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-users');

        $user = User::findOrFail($id);

        if ($user->id === Auth::id()) {
            return;
        }

        // Keep at least one active super administrator.
        if ($user->isSuperAdmin() && $user->is_active) {
            $activeCount = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->count();

            if ($activeCount <= 1) {
                return;
            }
        }

        $user->is_active = ! $user->is_active;
        $user->save();
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('manage-users');

        if ($id === Auth::id()) {
            return;
        }

        $this->deleteUserId = $id;
    }

    public function deleteUser(): void
    {
        Gate::authorize('manage-users');

        if (! $this->deleteUserId) {
            return;
        }

        $user = User::findOrFail($this->deleteUserId);

        if ($user->id === Auth::id()) {
            return;
        }

        if ($user->isSuperAdmin()) {
            $activeCount = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->count();

            if ($user->is_active && $activeCount <= 1) {
                return;
            }
        }

        $user->delete();

        $this->deleteUserId = null;
    }

    public function cancelDelete(): void
    {
        $this->deleteUserId = null;
    }
};
?>

<div class="space-y-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">Users</h1>
            <p class="text-sm text-slate-500">
                Manage administrators and account access
            </p>
        </div>

        <a
            href="{{ route('admin.users.create') }}"
            class="rounded-lg bg-blue-600 px-5 py-2.5 font-medium text-white hover:bg-blue-700"
        >
            + Add User
        </a>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">

        <input
            wire:model.live.debounce.400ms="search"
            type="search"
            placeholder="Search by name or email..."
            class="mb-5 w-full rounded-lg border border-slate-300 px-4 py-3 md:max-w-md"
        >

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
                </thead>

                <tbody>
                @foreach ($this->users() as $user)
                    <tr wire:key="user-{{ $user->id }}" class="border-b">
                        <td class="px-4 py-4 font-medium">
                            {{ $user->name }}
                        </td>

                        <td class="px-4 py-4">
                            {{ $user->email }}
                        </td>

                        <td class="px-4 py-4">
                            {{ $user->role === 'super_admin' ? 'Super Admin' : 'Admin' }}
                        </td>

                        <td class="px-4 py-4">
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold',
                                    'bg-green-100 text-green-700' => $user->is_active,
                                    'bg-red-100 text-red-700' => ! $user->is_active,
                                ])>
                                    {{ $user->is_active ? 'Active' : 'Blocked' }}
                                </span>
                        </td>

                        <td class="px-4 py-4">
                            <div class="flex items-center gap-4">
                                <a
                                    href="{{ route('admin.users.edit', $user) }}"
                                    class="text-blue-600 hover:underline"
                                >
                                    Edit
                                </a>

                                @if ($user->id !== auth()->id())
                                    <button
                                        wire:click="toggleActive({{ $user->id }})"
                                        class="text-amber-600 hover:underline"
                                    >
                                        {{ $user->is_active ? 'Block' : 'Activate' }}
                                    </button>

                                    <button
                                        wire:click="confirmDelete({{ $user->id }})"
                                        class="text-red-600 hover:underline"
                                    >
                                        Delete
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            {{ $this->users()->links() }}
        </div>
    </div>

    @if ($deleteUserId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-xl font-bold">Delete user?</h2>
                <p class="mt-2 text-slate-600">
                    This action cannot be undone.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        wire:click="cancelDelete"
                        class="rounded-lg border px-4 py-2"
                    >
                        Cancel
                    </button>

                    <button
                        wire:click="deleteUser"
                        class="rounded-lg bg-red-600 px-4 py-2 text-white"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>
