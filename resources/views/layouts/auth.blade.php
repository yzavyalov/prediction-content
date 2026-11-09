<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title ?? 'Prediction Content' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>

<body class="min-h-screen bg-slate-950 antialiased">

<main
    class="relative flex min-h-screen items-center justify-center overflow-hidden bg-cover bg-center bg-no-repeat px-4 py-10"
    style="background-image: url('{{ asset('images/auth/login-background.png') }}');"
>
    {{-- Dark overlay --}}
    <div class="absolute inset-0 bg-slate-950/45"></div>

    {{-- Center shadow for better form readability --}}
    <div
        class="absolute inset-0"
        style="background: radial-gradient(circle at center, rgba(15, 23, 42, 0.10) 0%, rgba(2, 6, 23, 0.38) 100%);"
    ></div>

    {{-- Page content --}}
    <div class="relative z-10 w-full">
        {{ $slot }}
    </div>
</main>

@livewireScripts

</body>
</html>
