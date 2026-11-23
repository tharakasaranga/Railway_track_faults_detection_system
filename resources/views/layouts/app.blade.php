<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <script>
        (() => {
            let theme = null;
            try {
                theme = localStorage.getItem('railway-theme');
            } catch {
                // Fall back to the system preference when browser storage is unavailable.
            }

            document.documentElement.dataset.bsTheme = theme || (
                window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            );
        })();
    </script>

    <title>
        {{ config('app.name', 'Railway Fault Detection') }}
    </title>

    {{-- Bootstrap CSS and Vite assets --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Livewire styles --}}
    @livewireStyles
</head>

<body>

    <div class="d-flex min-vh-100">

        {{-- Sidebar --}}
        @include('components.sidebar')

        {{-- Main content area --}}
        <main class="flex-grow-1">

            {{-- Top navigation bar --}}
            <nav class="navbar navbar-expand-lg border-bottom shadow-sm">
                <div class="container-fluid">

                    <span class="navbar-brand fw-bold mb-0">
                        Railway Track Fault Detection
                    </span>

                    <div class="ms-auto d-flex align-items-center gap-3">
                        <span class="text-muted d-none d-md-inline">
                            AI-Based Railway Research System
                        </span>
                        <button
                            type="button"
                            id="theme-toggle"
                            class="btn btn-outline-secondary btn-sm rounded-pill px-3"
                            aria-label="Enable dark mode"
                            aria-pressed="false"
                        >
                            <span id="theme-toggle-icon" aria-hidden="true">🌙</span>
                            <span id="theme-toggle-label">Dark mode</span>
                        </button>
                    </div>

                </div>
            </nav>

            {{-- Page content --}}
            <div class="container-fluid p-4">

                {{-- Flash success message --}}
                @if (session()->has('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('success') }}

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                            aria-label="Close">
                        </button>
                    </div>
                @endif

                {{-- Flash error message --}}
                @if (session()->has('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session('error') }}

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                            aria-label="Close">
                        </button>
                    </div>
                @endif

                {{-- Livewire component content --}}
                {{ $slot }}

            </div>

        </main>

    </div>

    {{-- Livewire scripts --}}
    @livewireScripts

</body>
</html>