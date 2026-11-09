
<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.auth')] class extends Component
{
    public string $login = '';
    public string $password = '';

    public function authenticate()
    {
        $this->validate([
            'login' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $authenticated = Auth::attempt([
            'email' => trim($this->login),
            'password' => $this->password,
            'is_active' => 1,
        ]);

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'login' => 'Invalid email or password, or account is inactive.',
            ]);
        }

        if (! in_array(Auth::user()->role, ['super_admin', 'admin'], true)) {
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'Access to the administration panel is denied.',
            ]);
        }

        session()->regenerate();

        return $this->redirect('/admin', navigate: true);
    }
};
?>

<div class="mx-auto flex w-full max-w-xl flex-col items-center">

    {{-- Brand --}}
    <div class="mb-7 text-center">
        <div class="mb-3 flex items-center justify-center gap-3">
            <div class="flex items-end gap-1">
                <span class="h-4 w-2 rounded-sm bg-cyan-400"></span>
                <span class="h-6 w-2 rounded-sm bg-sky-400"></span>
                <span class="h-8 w-2 rounded-sm bg-blue-500"></span>

                <svg
                    class="-ml-1 mb-5 h-5 w-5 text-sky-400"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    aria-hidden="true"
                >
                    <path d="M5 17 17 5"/>
                    <path d="M8 5h9v9"/>
                </svg>
            </div>

            <h1 class="text-3xl font-semibold tracking-tight text-white drop-shadow-lg md:text-4xl">
                Prediction Content
            </h1>
        </div>

        <p class="text-base font-medium tracking-wide text-slate-300 md:text-lg">
            News to Prediction Markets
        </p>
    </div>

    {{-- Login card --}}
    <div
        class="w-full rounded-3xl border border-sky-300/40 bg-slate-900/70 px-6 py-8 shadow-2xl shadow-black/40 backdrop-blur-xl sm:px-10 sm:py-10"
    >
        <h2 class="mb-8 text-center text-2xl font-semibold text-white sm:text-3xl">
            Admin Sign In
        </h2>

        <form wire:submit.prevent="authenticate" class="space-y-5">

            {{-- Email --}}
            <div>
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                        <svg
                            class="h-6 w-6 text-slate-300"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.8"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.118a7.5 7.5 0 0 1 15 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.5-1.632Z"
                            />
                        </svg>
                    </div>

                    <input
                        id="login-email"
                        name="email"
                        wire:model.blur="login"
                        type="email"
                        autocomplete="username"
                        placeholder="Email"
                        aria-label="Email"
                        @error('login') aria-invalid="true" @enderror
                        class="h-16 w-full rounded-2xl border border-slate-400/50 bg-slate-700/45 pl-16 pr-5 text-lg text-white outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:bg-slate-700/60 focus:ring-4 focus:ring-sky-400/10"
                    >
                </div>

                @error('login')
                <p class="mt-2 text-sm text-red-300" role="alert">
                    {{ $message }}
                </p>
                @enderror
            </div>

            {{-- Password --}}
            <div x-data="{ showPassword: false }">
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                        <svg
                            class="h-6 w-6 text-slate-300"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.8"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 0h10.5A2.25 2.25 0 0 1 19.5 12.75v6A2.25 2.25 0 0 1 17.25 21H6.75A2.25 2.25 0 0 1 4.5 18.75v-6a2.25 2.25 0 0 1 2.25-2.25Z"
                            />
                        </svg>
                    </div>

                    <input
                        id="login-password"
                        name="password"
                        wire:model="password"
                        x-bind:type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        placeholder="Password"
                        aria-label="Password"
                        @error('password') aria-invalid="true" @enderror
                        class="h-16 w-full rounded-2xl border border-slate-400/50 bg-slate-700/45 pl-16 pr-16 text-lg text-white outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:bg-slate-700/60 focus:ring-4 focus:ring-sky-400/10"
                    >

                    <button
                        type="button"
                        x-on:click="showPassword = !showPassword"
                        x-bind:aria-label="showPassword ? 'Hide password' : 'Show password'"
                        class="absolute inset-y-0 right-0 flex w-16 items-center justify-center text-slate-300 transition hover:text-white"
                    >
                        <svg
                            class="h-6 w-6"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.8"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"
                            />
                            <circle cx="12" cy="12" r="2.75"/>
                        </svg>
                    </button>
                </div>

                @error('password')
                <p class="mt-2 text-sm text-red-300" role="alert">
                    {{ $message }}
                </p>
                @enderror
            </div>

            {{-- Login button --}}
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="authenticate"
                class="mt-2 flex h-16 w-full items-center justify-center gap-3 rounded-2xl bg-gradient-to-r from-sky-400 to-blue-600 text-lg font-semibold text-white shadow-lg shadow-blue-950/30 transition hover:brightness-110 active:scale-[0.99] disabled:cursor-wait disabled:opacity-70"
            >
                <svg
                    wire:loading.remove
                    wire:target="authenticate"
                    class="h-6 w-6"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3-6 3 3m0 0-3 3m3-3H9"
                    />
                </svg>

                <span wire:loading.remove wire:target="authenticate">
                    Log In
                </span>

                <span wire:loading wire:target="authenticate">
                    Signing In...
                </span>
            </button>

        </form>
    </div>
</div>
