<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- FIX 1: Prevent duplicate title in browser tab --}}
    <title>{{ (isset($title) && $title && $title !== config('app.name')) ? $title . ' — ' . config('app.name') : config('app.name') }}</title>

    <!-- Google Font: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Immediate Theme Application (Zero Flicker) -->
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <!-- Universal Zero-Distortion Theme Engine -->
    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif !important;
            letter-spacing: -0.01em;
        }

        /* Desktop Sidebar Collapse Dynamics */
        @media (min-width: 768px) {
            #app-sidebar.is-collapsed {
                width: 0 !important;
                min-width: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                border-right: none !important;
                opacity: 0 !important;
            }
        }

        /* Universal Dark Theme Overrides (Fixes ALL pages without touching .php files) */
        html.dark body {
            background-color: #0b1120 !important;
            color: #f1f5f9 !important;
        }

        html.dark .brand-logo-img {
            filter: brightness(0) invert(1) drop-shadow(0 1px 3px rgba(0, 0, 0, 0.6));
        }

        /* Surfaces */
        html.dark header,
        html.dark aside,
        html.dark #app-sidebar,
        html.dark .bg-white {
            background-color: #111827 !important;
            border-color: #1e293b !important;
        }

        /* Dividers & Borders */
        html.dark .border-slate-200,
        html.dark .border-slate-100,
        html.dark .border-slate-300,
        html.dark tr,
        html.dark td,
        html.dark th {
            border-color: #1e293b !important;
        }

        html.dark .divide-slate-100 > * + *,
        html.dark .divide-slate-200 > * + * {
            border-color: #1e293b !important;
        }

        /* Typography */
        html.dark .text-slate-900,
        html.dark .text-slate-800,
        html.dark .text-slate-700 {
            color: #f8fafc !important;
        }

        html.dark .text-slate-600,
        html.dark .text-slate-500,
        html.dark .text-slate-400 {
            color: #94a3b8 !important;
        }

        /* Badges & Containers: Convert White Blobs to Translucent Dark Badges */
        html.dark .bg-slate-50,
        html.dark .bg-slate-100 {
            background-color: #1e293b !important;
            color: #e2e8f0 !important;
        }

        html.dark .bg-emerald-50 {
            background-color: rgba(16, 185, 129, 0.16) !important;
            color: #34d399 !important;
        }

        html.dark .bg-rose-50,
        html.dark .bg-red-50 {
            background-color: rgba(244, 63, 94, 0.16) !important;
            color: #fb7185 !important;
        }

        html.dark .bg-amber-50 {
            background-color: rgba(245, 158, 11, 0.16) !important;
            color: #fcd34d !important;
        }

        html.dark .bg-sky-50,
        html.dark .bg-blue-50 {
            background-color: rgba(14, 165, 233, 0.16) !important;
            color: #38bdf8 !important;
        }

        html.dark .bg-indigo-50 {
            background-color: rgba(99, 102, 241, 0.16) !important;
            color: #818cf8 !important;
        }

        /* Text Accents in Dark Mode */
        html.dark .text-emerald-700,
        html.dark .text-emerald-600 {
            color: #34d399 !important;
        }

        html.dark .text-rose-700,
        html.dark .text-rose-600 {
            color: #fb7185 !important;
        }

        html.dark .text-amber-800,
        html.dark .text-amber-900 {
            color: #fcd34d !important;
        }

        /* Form Inputs */
        html.dark input,
        html.dark select,
        html.dark textarea {
            background-color: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-[#0b1120] dark:text-slate-100">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-emerald-600 focus:px-3 focus:py-2 focus:text-white">
        {{ __('Skip to main content') }}
    </a>

    <div class="flex min-h-screen flex-col">
        <!-- Top Navbar -->
        <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 md:px-6 dark:border-slate-800 dark:bg-[#111827]">
            <div class="flex items-center gap-3">
                <!-- Sidebar Toggle Button (Controls Both Desktop & Mobile) -->
                <button type="button" 
                        id="global-sidebar-toggle"
                        class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-600 transition-all hover:bg-slate-100 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-white" 
                        aria-label="{{ __('Toggle navigation sidebar') }}"
                        title="Toggle Sidebar (Hide/Show)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                    </svg>
                </button>

                <a href="{{ route('home') }}" class="flex items-center transition-opacity hover:opacity-90">
                    <img src="{{ asset('images/logo.svg') }}" alt="Officers' Mess" class="brand-logo-img h-10 w-auto" />
                </a>
            </div>

            <div class="flex items-center gap-3">
                <!-- Theme Toggle Button -->
                <button type="button" 
                        onclick="toggleTheme()" 
                        class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-600 transition-all hover:bg-slate-100 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-amber-400 dark:hover:bg-slate-700" 
                        aria-label="Toggle dark mode"
                        title="Toggle Light / Dark Mode">
                    <svg id="theme-icon-sun" class="hidden h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <svg id="theme-icon-moon" class="hidden h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>

                <x-notification-bell />

                <div class="hidden h-4 w-px bg-slate-200 sm:block dark:bg-slate-700"></div>

                <span class="hidden text-xs font-bold text-slate-700 sm:inline dark:text-slate-200">{{ auth()->user()?->name }}</span>

                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="flex items-center gap-1.5 rounded-xl px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition-colors hover:bg-rose-50 hover:text-rose-600 dark:text-slate-400 dark:hover:bg-rose-950/40 dark:hover:text-rose-300">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
                        </svg>
                        <span class="hidden sm:inline">{{ __('Log out') }}</span>
                    </button>
                </form>
            </div>
        </header>

        <div class="flex flex-1">
            <!-- Sidebar with Smooth Transition on Toggle -->
            <aside id="app-sidebar" 
                   class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full overflow-y-auto border-r border-slate-200 bg-white transition-all duration-300 ease-in-out md:static md:translate-x-0 dark:border-slate-800 dark:bg-[#111827]">
                <x-sidebar />
            </aside>

            <div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-slate-900/60 backdrop-blur-xs md:hidden"></div>

            <!-- Content Area -->
            <main id="main-content" class="flex-1 px-4 py-6 md:px-8 md:py-8 transition-all duration-300">
                <div class="mx-auto w-full max-w-384">
                    @if (session('success'))
                        <div role="alert" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">{{ session('success') }}</div>
                    @endif
                    @if (session('error'))
                        <div role="alert" class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ session('error') }}</div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <!-- Global App Engine (Theme + Collapsible Sidebar Controller) -->
    <script>
        // Theme Switcher Logic
        function updateThemeIcons() {
            const isDark = document.documentElement.classList.contains('dark');
            const sun = document.getElementById('theme-icon-sun');
            const moon = document.getElementById('theme-icon-moon');
            if (sun && moon) {
                if (isDark) {
                    sun.classList.remove('hidden');
                    moon.classList.add('hidden');
                } else {
                    sun.classList.add('hidden');
                    moon.classList.remove('hidden');
                }
            }
        }

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
            updateThemeIcons();
        }

        // Sidebar Controller (Collapsible for Desktop & Drawer for Mobile)
        document.addEventListener('DOMContentLoaded', function () {
            updateThemeIcons();

            const toggleBtn = document.getElementById('global-sidebar-toggle');
            const sidebar = document.getElementById('app-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');
            if (!toggleBtn || !sidebar) return;

            // Restore desktop collapsed preference
            if (window.innerWidth >= 768 && localStorage.getItem('sidebar-collapsed') === 'true') {
                sidebar.classList.add('is-collapsed');
            }

            toggleBtn.addEventListener('click', function () {
                if (window.innerWidth >= 768) {
                    // Desktop Collapse / Expand Toggle
                    sidebar.classList.toggle('is-collapsed');
                    localStorage.setItem('sidebar-collapsed', sidebar.classList.contains('is-collapsed'));
                } else {
                    // Mobile Drawer Toggle
                    const isOpen = !sidebar.classList.contains('-translate-x-full');
                    if (isOpen) {
                        sidebar.classList.add('-translate-x-full');
                        if (backdrop) backdrop.classList.add('hidden');
                    } else {
                        sidebar.classList.remove('-translate-x-full');
                        if (backdrop) backdrop.classList.remove('hidden');
                    }
                }
            });

            if (backdrop) {
                backdrop.addEventListener('click', function () {
                    sidebar.classList.add('-translate-x-full');
                    backdrop.classList.add('hidden');
                });
            }
        });
    </script>
</body>
</html>