@extends('layouts.app')
@section('content')
    @php
        use Carbon\Carbon;
        $todayStr = now()->toDateString();
        $monthCarbon = Carbon::parse($monthStr);
    @endphp

    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold leading-tight text-slate-900">{{ __('Monthly meal grid') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('Mark meals for each member across the full month. Save once.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('mess.meals.monthly', ['month' => $monthCarbon->copy()->subMonth()->format('Y-m')]) }}"
               class="btn btn-secondary btn-sm" aria-label="{{ __('Previous month') }}">&larr;</a>
            <input type="month" value="{{ $monthStr }}" data-month-picker
                   class="input w-auto text-sm">
            <a href="{{ route('mess.meals.monthly', ['month' => $monthCarbon->copy()->addMonth()->format('Y-m')]) }}"
               class="btn btn-secondary btn-sm" aria-label="{{ __('Next month') }}">&rarr;</a>
        </div>
    </header>

    {{-- Legend --}}
    <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-500">
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-emerald-500 text-[10px] font-bold text-white">B</span>
            {{ __('Meal on') }}
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded border border-slate-200 bg-slate-50 text-[10px] font-bold text-slate-400">L</span>
            {{ __('Meal off') }}
        </span>
        <span class="hidden text-slate-400 sm:inline">·</span>
        <span>{{ __('Tap :b / :l / :d to toggle Breakfast / Lunch / Dinner.', ['b' => 'B', 'l' => 'L', 'd' => 'D']) }}</span>
    </div>

    <form method="POST" action="{{ route('mess.meals.monthly.save') }}" data-monthly-meal-form>
        @csrf
        <input type="hidden" name="month" value="{{ $monthStr }}" />

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <button type="button" data-preset="all" class="btn btn-secondary btn-sm">
                {{ __('Mark all 3 meals (all members, all days)') }}
            </button>
            <button type="button" data-preset="none" class="btn btn-secondary btn-sm">
                {{ __('Clear all meals') }}
            </button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 border-collapse" data-monthly-grid>
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="sticky left-0 z-20 bg-slate-50 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 sm:px-4" style="min-width:150px">
                            {{ __('Member') }}
                        </th>
                        @for ($d = 1; $d <= $days_in_month; $d++)
                            @php
                                $date = $monthCarbon->copy()->day($d);
                                $isWeekend = $date->isWeekend();
                                $dateStr = $date->format('Y-m-d');
                                $isClosed = in_array($dateStr, $closed_dates);
                                $isToday = $dateStr === $todayStr;
                            @endphp
                            <th scope="col"
                                class="px-1 py-2 text-center text-xs font-semibold tracking-wide {{ $isWeekend ? 'text-amber-600' : 'text-slate-500' }} {{ $isClosed ? 'bg-red-50' : ($isToday ? 'bg-emerald-50' : '') }}"
                                style="min-width:{{ $isClosed ? '34' : '52' }}px">
                                <div class="{{ $isToday ? 'text-emerald-700' : '' }}">{{ $d }}</div>
                                <div class="text-[10px] uppercase {{ $isToday ? 'text-emerald-600' : '' }}">{{ $date->format('D') }}</div>
                                @if ($isClosed)
                                    <div class="text-[9px] text-red-500">&#x2716;</div>
                                @endif
                            </th>
                        @endfor
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse ($members as $row)
                        <tr class="transition-colors hover:bg-slate-50/60">
                            <td class="sticky left-0 z-10 bg-white px-3 py-2 text-sm sm:px-4" style="min-width:150px">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-slate-900 truncate max-w-[110px]">{{ $row->member->name }}</span>
                                    <div class="flex gap-0.5" role="group" aria-label="{{ __('Row actions for :name', ['name' => $row->member->name]) }}">
                                        <button type="button" data-row-preset="all" data-row-member="{{ $row->member->id }}"
                                                class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-emerald-100 hover:text-emerald-700"
                                                title="{{ __('All meals') }}">&#x2713;</button>
                                        <button type="button" data-row-preset="none" data-row-member="{{ $row->member->id }}"
                                                class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-red-100 hover:text-red-700"
                                                title="{{ __('Clear all') }}">&#x2717;</button>
                                    </div>
                                </div>
                                @if ($row->member->room_or_seat)
                                    <div class="text-xs text-slate-500 truncate">{{ $row->member->room_or_seat }}</div>
                                @endif
                            </td>
                            @foreach ($row->days as $day)
                                @php
                                    $dayIsToday = ($day->date ?? null) === $todayStr;
                                @endphp
                                <td class="px-0.5 py-1.5 text-center align-middle sm:px-1 {{ $day->editable ? '' : 'bg-slate-50' }} {{ $dayIsToday && $day->editable ? 'bg-emerald-50/50' : '' }}"
                                    @if (! $day->editable)
                                    title="{{ $day->reason ?? __('Not editable') }}"
                                    @endif
                                >
                                    @if ($day->editable)
                                        <input type="hidden" name="entries[{{ $row->member->id }}_{{ $day->day }}][member_id]" value="{{ $row->member->id }}" />
                                        <input type="hidden" name="entries[{{ $row->member->id }}_{{ $day->day }}][date]" value="{{ $day->date }}" />
                                        @php
                                            // [key => [letter, label, is_on]]
                                            $mealToggles = [
                                                'breakfast' => ['B', __('Breakfast'), (bool) $day->breakfast],
                                                'lunch' => ['L', __('Lunch'), (bool) $day->lunch],
                                                'dinner' => ['D', __('Dinner'), (bool) $day->dinner],
                                            ];
                                        @endphp
                                        <div class="flex flex-col items-center gap-1">
                                            @foreach ($mealToggles as $meal => [$letter, $label, $on])
                                                <label class="relative inline-flex cursor-pointer items-center justify-center"
                                                       title="{{ $day->date }} — {{ $label }} — {{ $row->member->name }}">
                                                    <input type="checkbox"
                                                        name="entries[{{ $row->member->id }}_{{ $day->day }}][{{ $meal }}]"
                                                        value="1"
                                                        @checked($on)
                                                        data-meal-checkbox
                                                        data-member="{{ $row->member->id }}"
                                                        data-date="{{ $day->date }}"
                                                        data-meal="{{ $meal }}"
                                                        class="peer sr-only"
                                                        aria-label="{{ __(':date :meal for :name', ['date' => $day->date, 'meal' => $label, 'name' => $row->member->name]) }}">
                                                    <span class="inline-flex h-6 w-7 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-[11px] font-bold leading-none text-slate-400 transition-colors peer-checked:border-emerald-500 peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-400 peer-focus-visible:ring-offset-1 hover:border-slate-300">
                                                        {{ $letter }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-300" title="{{ $day->reason ?? '' }}">&#x2022;</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $days_in_month + 1 }}" class="px-4 py-10 text-center text-sm text-slate-500">
                                {{ __('No active members yet. Add members to start recording meals.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save all changes') }}
            </button>
            <a href="{{ route('mess.meals.index') }}" class="btn btn-ghost btn-sm">
                {{ __('Switch to daily grid') }}
            </a>
        </div>
    </form>

    @once
        <script>
            (function () {
                // Month picker navigation
                const monthPicker = document.querySelector('[data-month-picker]');
                if (monthPicker) {
                    monthPicker.addEventListener('change', function () {
                        if (monthPicker.value) {
                            window.location.href = '{{ route('mess.meals.monthly') }}' + '?month=' + monthPicker.value;
                        }
                    });
                }

                // Global presets (all/none) affect every editable checkbox.
                document.querySelectorAll('[data-preset]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const preset = btn.getAttribute('data-preset');
                        const value = preset === 'all';
                        document.querySelectorAll('[data-meal-checkbox]').forEach(function (cb) {
                            if (!cb.disabled) cb.checked = value;
                        });
                    });
                });

                // Per-member presets
                document.querySelectorAll('[data-row-preset]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const preset = btn.getAttribute('data-row-preset');
                        const memberId = btn.getAttribute('data-row-member');
                        const value = preset === 'all';
                        document.querySelectorAll(
                            '[data-meal-checkbox][data-member="' + memberId + '"]'
                        ).forEach(function (cb) {
                            if (!cb.disabled) cb.checked = value;
                        });
                    });
                });
            })();
        </script>
    @endonce
@endsection
