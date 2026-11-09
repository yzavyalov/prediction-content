<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title ?? 'Prediction Content' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-gray-100 text-gray-900">

<div class="flex min-h-screen">

    {{-- Sidebar --}}
    <aside class="flex w-64 shrink-0 flex-col bg-gray-900 text-white">

        {{-- Brand --}}
        <div class="border-b border-gray-800 px-6 py-5">
            <div class="text-xl font-semibold">
                Prediction Content
            </div>

            <div class="mt-1 text-xs text-gray-400">
                Administration
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 space-y-1 p-4">

            <a
                href="{{ route('admin.dashboard') }}"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                Dashboard
            </a>

            <a
                href="{{ route('admin.sources.index') }}"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                Sources
            </a>

            <a
                href="{{ route('admin.generation-profiles.index') }}"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                Generation Profiles
            </a>

            {{-- User Management: Super Admin only --}}
            @can('manage-users')
                <a
                    href="{{ route('admin.users.index') }}"
                    class="flex items-center gap-3 rounded-lg px-4 py-2.5 hover:bg-gray-800"
                >
                    <svg
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M16 18.5a4.5 4.5 0 0 0-9 0M11.5 13a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17 9h5m-2.5-2.5v5"
                        />
                    </svg>

                    Users
                </a>
            @endcan

            <a
                href="{{ route('admin.discovery.index') }}"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                Discovery
            </a>

            <a
                href="/admin/events"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                Events
            </a>

            <a
                href="/admin/markets"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                Markets
            </a>

            <a
                href="/admin/ai-attempts"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                AI Attempts
            </a>

            <a
                href="/admin/settings"
                class="block rounded-lg px-4 py-2.5 hover:bg-gray-800"
            >
                Settings
            </a>

        </nav>

        {{-- Sidebar footer --}}
        <div class="border-t border-gray-800 p-4">

            @auth
                <div class="mb-3 px-4">
                    <div class="truncate text-sm font-medium text-gray-200">
                        {{ auth()->user()->name }}
                    </div>

                    <div class="truncate text-xs text-gray-400">
                        {{ auth()->user()->email }}
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf

                    <button
                        type="submit"
                        class="flex w-full items-center gap-3 rounded-lg px-4 py-2.5 text-left text-sm text-red-300 transition hover:bg-gray-800 hover:text-red-200"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9"
                            />
                        </svg>

                        Log Out
                    </button>
                </form>
            @endauth

        </div>

    </aside>

    {{-- Main --}}
    <main class="min-w-0 flex-1">

        <header class="border-b border-gray-200 bg-white px-8 py-4">
            <div class="text-sm text-gray-500">
                AI Prediction Market Content Platform
            </div>
        </header>

        <div class="p-8">
            {{ $slot }}
        </div>

    </main>

</div>

@livewireScripts
</body>
</html>
