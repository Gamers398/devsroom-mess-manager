@extends('layouts.app')
@section('content')
    @php
        use App\Models\MonthlyClosing;
        use App\Support\Money;
        use Carbon\Carbon;

        $now = Carbon::now();
        $currentClosing = MonthlyClosing::query()
            ->where('year', $now->year)
            ->where('month', $now->month)
            ->first();
        $cards = $cards ?? [
            'total_members' => 0, 'today_meals' => 0.0,
            'total_meals' => 0.0,
            'monthly_expenses' => 0.0, 'meal_rate' => 0.0,
            'total_credit' => 0.0, 'total_dues' => 0.0,
            'total_member_balance' => 0.0,
        ];
        $pendingMealOff = $pendingMealOff ?? 0;
    @endphp

    {{-- Typography & Aesthetic Injection --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .font-sans-custom { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif !important; }
    </style>

    <div class="font-sans-custom space-y-6">
        <header>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                {{ __('Dashboard') }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('Welcome, :name', ['name' => auth()->user()->name]) }}
            </p>
        </header>

        @if ($currentClosing)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50/80 p-4 text-sm text-amber-900 shadow-sm backdrop-blur-sm">
                <div>
                    <p class="font-bold">{{ __('MONTH CLOSED — :label is locked.', ['label' => $now->format('F Y')]) }}</p>
                    <p class="mt-0.5 text-xs text-amber-800">{{ __('Meal/expense/payment writes for this month are disabled. Use corrections to adjust a closed month.') }}</p>
                </div>
                <a href="{{ route('mess.closings.show', $currentClosing) }}" class="inline-flex items-center rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm transition-colors hover:bg-amber-100">
                    {{ __('View closing') }}
                </a>
            </div>
        @endif

        {{-- DASH-03: Pending meal-off alert banner --}}
        @if ($pendingMealOff > 0)
            <a href="{{ route('mess.meal-off.index') }}" class="block rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 shadow-sm transition-all hover:bg-amber-100 hover:shadow">
                {{ trans_choice(':count pending meal off request awaiting approval|:count pending meal off requests awaiting approval', $pendingMealOff) }}
            </a>
        @endif

        {{-- DASH-01: Modern Aesthetic Stat Cards Grid --}}
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            
            {{-- 1. Total Members --}}
            <div class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('Total Members') }}</span>
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-700 transition-colors group-hover:bg-slate-900 group-hover:text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                </div>
                <p class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900">{{ number_format((int) $cards['total_members']) }}</p>
                <span class="mt-1 block text-xs text-slate-400">{{ __('Active mess participants') }}</span>
            </div>

            {{-- 2. Today's Meals --}}
            <div class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-sky-300 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __("Today's Meals") }}</span>
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 text-sky-600 transition-colors group-hover:bg-sky-600 group-hover:text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <p class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900">{{ number_format((float) $cards['today_meals'], 1) }}</p>
                <span class="mt-1 block text-xs text-slate-400">{{ __('Logged for today') }}</span>
            </div>

            {{-- 3. Total Meals (this month) --}}
            <div class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('Total Meals (this month)') }}</span>
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 transition-colors group-hover:bg-indigo-600 group-hover:text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                </div>
                <p class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900">{{ number_format((float) ($cards['total_meals'] ?? 0), 1) }}</p>
                <span class="mt-1 block text-xs text-slate-400">{{ __('Cumulative monthly total') }}</span>
            </div>

            {{-- 4. Current Meal Rate (Featured Gradient Card) --}}
@php
    $mealRateHint = ((float) $cards['meal_rate'] === 0.0) ? __('no bazar recorded yet') : __('Calculated from bazar & meals');
@endphp
<div class="relative overflow-hidden rounded-2xl p-5 shadow-md transition-all duration-200 hover:-translate-y-0.5 sm:col-span-2 lg:col-span-1"
     style="background: linear-gradient(135deg, #065f46 0%, #0b1e33 100%) !important; color: #ffffff !important;">
    <div class="flex items-center justify-between">
        <span class="text-xs font-bold uppercase tracking-wider" style="color: #6ee7b7 !important;">{{ __('Current Meal Rate') }}</span>
        <div class="flex h-9 w-9 items-center justify-center rounded-xl backdrop-blur-md" style="background: rgba(255, 255, 255, 0.15); color: #6ee7b7;">
            <span class="text-base font-bold">৳</span>
        </div>
    </div>
    <p class="mt-3 text-3xl font-extrabold tracking-tight" style="color: #ffffff !important;">
        {{ Money::taka((float) $cards['meal_rate']) }} 
        <span class="text-sm font-normal" style="color: #a7f3d0 !important;">/ {{ __('meal') }}</span>
    </p>
    <span class="mt-1 block text-xs" style="color: #a7f3d0 !important;">{{ $mealRateHint }}</span>
</div>

            {{-- 5. Monthly Expenses --}}
            <div class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('Monthly Expenses') }}</span>
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-600 transition-colors group-hover:bg-amber-500 group-hover:text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </div>
                </div>
                <p class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900">{{ Money::taka((float) $cards['monthly_expenses']) }}</p>
                <span class="mt-1 block text-xs text-slate-400">{{ __('Total bazar & expenditures') }}</span>
            </div>

            {{-- 6. Total Credit (advance) --}}
            <div class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('Total Credit (advance)') }}</span>
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 transition-colors group-hover:bg-emerald-600 group-hover:text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                </div>
                <p class="mt-3 text-3xl font-extrabold tracking-tight text-emerald-600">{{ Money::taka((float) ($cards['total_credit'] ?? 0)) }}</p>
                <span class="mt-1 block text-xs text-slate-400">{{ __('Prepaid by members') }}</span>
            </div>

            {{-- 7. Total Dues --}}
            <div class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-rose-300 hover:shadow-md sm:col-span-2 lg:col-span-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('Total Dues') }}</span>
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-600 transition-colors group-hover:bg-rose-600 group-hover:text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                </div>
                <p class="mt-3 text-3xl font-extrabold tracking-tight text-rose-600">{{ Money::taka((float) ($cards['total_dues'] ?? 0)) }}</p>
                <span class="mt-1 block text-xs text-slate-400">{{ __('Owed by members') }}</span>
            </div>
        </section>

        {{-- Report widgets (replaces the old 3 trend charts) --}}
        <section class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Members with dues --}}
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">{{ __('Members with dues') }}</h3>
                @if (empty($membersWithDues))
                    <p class="text-sm text-slate-500">{{ __('No one currently owes the mess. 🎉') }}</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($membersWithDues as $m)
                            <li class="flex items-center justify-between py-2.5">
                                <a href="{{ route('mess.members.wallet', $m['id']) }}" class="text-sm font-semibold text-slate-900 hover:text-emerald-700 hover:underline">{{ $m['name'] }}</a>
                                <span class="rounded-lg bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700">{{ Money::taka(abs($m['net'])) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Top eaters --}}
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">{{ __('Top eaters this month') }}</h3>
                @if (empty($topEaters))
                    <p class="text-sm text-slate-500">{{ __('No meals recorded yet this month.') }}</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($topEaters as $m)
                            <li class="flex items-center justify-between py-2.5">
                                <span class="text-sm font-semibold text-slate-900">{{ $m['name'] }}</span>
                                <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ number_format((float) $m['meals'], 1) }} {{ __('meals') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Bazar vs collection (bar) --}}
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">{{ __('Spend vs collection this month') }}</h3>
                <div style="height: 240px;">
                    <canvas id="bazar-collection-chart"></canvas>
                </div>
            </div>

            {{-- Expense category mix (doughnut) --}}
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">{{ __('Expense categories this month') }}</h3>
                @if (empty($expenseCategoryMix))
                    <p class="text-sm text-slate-500">{{ __('No expenses recorded yet this month.') }}</p>
                @else
                    <div style="height: 240px;">
                        <canvas id="expense-category-chart"></canvas>
                    </div>
                @endif
            </div>
        </section>

        @once
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    window.initDashboardChart('bazar-collection-chart', {
                        type: 'bar',
                        data: {
                            labels: [@json([__('Spend'), __('Collected')])],
                            datasets: [{
                                label: '@lang('Amount')',
                                data: [@json([(float) ($bazarVsCollection['spend'] ?? 0), (float) ($bazarVsCollection['collected'] ?? 0)])],
                                backgroundColor: ['#f43f5e', '#059669'],
                                borderRadius: 6,
                            }],
                        },
                    });
                    @if (! empty($expenseCategoryMix))
                        window.initDashboardChart('expense-category-chart', {
                            type: 'doughnut',
                            data: {
                                labels: @json(collect($expenseCategoryMix)->pluck('label')),
                                datasets: [{
                                    data: @json(collect($expenseCategoryMix)->pluck('amount')),
                                    backgroundColor: ['#059669', '#0ea5e9', '#f59e0b', '#8b5cf6', '#f43f5e', '#64748b', '#10b981', '#ec4899'],
                                    borderWidth: 2,
                                    borderColor: '#ffffff',
                                }],
                            },
                        });
                    @endif
                });
            </script>
        @endonce
    </div>
@endsection