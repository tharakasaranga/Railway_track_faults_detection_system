<div
    class="d-flex flex-column flex-shrink-0 p-3 text-white bg-dark"
    style="width: 260px; min-height: 100vh;"
>
    {{-- Application title --}}
    <a
        href="{{ route('dashboard') }}"
        class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none"
    >
        <span class="fs-5 fw-bold">
            Railway Fault Detection
        </span>
    </a>

    <hr>

    {{-- Navigation menu --}}
    <ul class="nav nav-pills flex-column mb-auto">

        {{-- Dashboard --}}
        <li class="nav-item mb-2">
            <a
                href="{{ route('dashboard') }}"
                class="nav-link text-white {{ request()->routeIs('dashboard') ? 'active' : '' }}"
            >
                <span class="me-2">📊</span>
                Dashboard
            </a>
        </li>

        {{-- Upload video --}}
        <li class="nav-item mb-2">
            <a
                href="{{ route('videos.upload') }}"
                class="nav-link text-white {{ request()->routeIs('videos.upload') ? 'active' : '' }}"
            >
                <span class="me-2">📤</span>
                Analyze Video
            </a>
        </li>

        {{-- Video results --}}
        <li class="nav-item mb-2">
            <a
                href="{{ route('videos.index') }}"
                class="nav-link text-white {{ request()->routeIs('videos.index') ? 'active' : '' }}"
            >
                <span class="me-2">🎞️</span>
                Analysis Results
            </a>
        </li>

        {{-- Models --}}
        <li class="nav-item mb-2">
            <a
                href="{{ route('models.index') }}"
                class="nav-link text-white {{ request()->routeIs('models.*') ? 'active' : '' }}"
            >
                <span class="me-2">🧠</span>
                Models
            </a>
        </li>

        {{-- Research analytics --}}
        <li class="nav-item mb-2">
            <a
                href="{{ route('research.analytics') }}"
                class="nav-link text-white {{ request()->routeIs('research.*') ? 'active' : '' }}"
            >
                <span class="me-2">📈</span>
                Research Analytics
            </a>
        </li>

    </ul>

    <hr>

    {{-- System information --}}
    <div class="small text-secondary">
        <div class="fw-bold text-white mb-1">
            AI Railway Research System
        </div>

        <div>
            YOLO-based Fault Localization
        </div>

        <div>
            Laravel + Livewire
        </div>
    </div>
</div>