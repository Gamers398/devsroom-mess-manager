@extends('layouts.app')
@section('content')
    @php
        use App\Support\Money;
        use Carbon\Carbon;

        $period = Carbon::create($year, $month, 1)->translatedFormat('F Y');
        $isSnapshot = ($data['source'] ?? 'live') === 'snapshot';
        $members = $data['members'] ?? [];
        $totalDue = collect($members)->sum('due');
        // Balance = the member's TRUE net position, consistent with the Due
        // column. For an OPEN month the stored advance hasn't been applied to
        // this month's bill yet, so net it out: money in (advance + payments)
        // − money owed (bill + prior due). For a CLOSED month the snapshot's
        // advance/due ARE the settled closing figures (the bill is already
        // baked into them), so advance − due is correct and the bill must NOT
        // be re-subtracted (would double-count).
        $totalNet = $isSnapshot
            ? collect($members)->sum(fn ($r) => ($r['advance_balance'] ?? 0) - ($r['due_balance'] ?? 0))
            : collect($members)->sum(fn ($r) => ($r['advance_balance'] ?? 0) + ($r['bill_payments'] ?? 0) - ($r['bill'] ?? 0) - ($r['due_balance'] ?? 0));
        $hasData = ! empty($members);
    @endphp

    <header class="mb-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold leading-tight text-slate-900">
                    {{ __('Monthly Report') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $period }}
                    @if ($isSnapshot)
                        <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                            {{ __('Closed month') }}
                        </span>
                    @endif
                </p>
            </div>
        </div>
    </header>

    <div class="mb-6">
        <x-report-toolbar route="mess.reports.monthly" :year="$year" :month="$month" showExports="true" :from="$monthRange['first'] ?? null" :to="$monthRange['last'] ?? null" :filters="request()->query('from') || request()->query('to') || request()->query('category_id') || request()->query('month') ? request()->only(['from', 'to', 'category_id', 'month']) : []" />
    </div>

    @if (! $hasData)
        <x-empty-state
            :title="__('No data for :month yet', ['month' => $period])"
            :description="__('Once meals, bazar, or fixed expenses are entered for this month, the report will appear here.')" />
    @else
        {{-- Totals grid --}}
        <section class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Members') }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ count($members) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Meals') }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ number_format((float) $data['total_meals'], 1) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Meal rate') }}</p>
                @php
                    $totalBazar = (float) ($data['total_bazar'] ?? 0.0);
                    $mealRateHint = ((float) $data['meal_rate'] === 0.0)
                        ? ($totalBazar > 0.0 ? __('bazar recorded, but no meals yet') : __('no bazar recorded yet'))
                        : null;
                @endphp
                @if ($mealRateHint)
                    <p class="mt-2 text-lg font-bold text-slate-900">{{ Money::taka(0) }} <span class="text-sm font-normal text-slate-500">/ {{ __('meal') }}</span></p>
                    <p class="mt-1 text-xs text-slate-500">{{ $mealRateHint }}</p>
                @else
                    <p class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ Money::taka($data['meal_rate']) }} <span class="text-sm font-normal text-slate-500">/ {{ __('meal') }}</span></p>
                @endif
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Total bazar') }}</p>
                <p class="mt-2 text-xl font-bold tracking-tight text-slate-900">{{ Money::taka($data['total_bazar']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Total fixed') }}</p>
                <p class="mt-2 text-xl font-bold tracking-tight text-slate-900">{{ Money::taka($data['total_fixed']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Balance (net)') }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight {{ $totalNet < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                    {{ $totalNet < 0 ? __('Owes') : __('Credit') }} {{ Money::taka(abs($totalNet)) }}
                </p>
            </div>
        </section>

        {{-- Per-member table --}}
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th rowspan="2" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Member') }}</th>
                            <th rowspan="2" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Status') }}</th>
                            <th colspan="4" class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('This month') }}</th>
                            <th rowspan="2" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Brought forward') }}</th>
                            <th rowspan="2" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Closing (net)') }}</th>
                        </tr>
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Meals') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Bill') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Paid') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Due') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($members as $row)
                            <tr class="transition-colors hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-900">
                                    <a href="{{ route('mess.reports.member-statement', ['member_id' => $row['member_id'], 'year' => $year, 'month' => $month]) }}"
                                       class="text-emerald-700 hover:underline">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $statusClasses = match ($row['status'] ?? 'active') {
                                            'former' => 'bg-slate-100 text-slate-700',
                                            'inactive' => 'bg-rose-100 text-rose-700',
                                            default => 'bg-emerald-100 text-emerald-700',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full {{ $statusClasses }} px-2 py-0.5 text-xs font-medium">
                                        {{ __(ucfirst($row['status'] ?? 'active')) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row['meals'], 1) }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums">{{ Money::taka($row['bill'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ Money::taka($row['bill_payments'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ Money::taka($row['due'] ?? 0) }}</td>
                                @php
                                    // Brought forward = opening net carried in from the prior
                                    // month. A prior-month advance deposit not yet consumed shows
                                    // here, NOT as this-month income. Guarded with ?? 0 so a
                                    // pre-deploy snapshot row (NULL column) degrades to 0.00.
                                    $rowBroughtForward = (float) ($row['brought_forward'] ?? 0);

                                    // Closing (net) — the member's true running net (unchanged
                                    // validated math). Open month: advance + payments − bill −
                                    // prior due. Closed month: advance − due (the snapshot's
                                    // advance/due ARE the settled closing figures).
                                    $rowNet = $isSnapshot
                                        ? (($row['advance_balance'] ?? 0) - ($row['due_balance'] ?? 0))
                                        : (($row['advance_balance'] ?? 0) + ($row['bill_payments'] ?? 0) - ($row['bill'] ?? 0) - ($row['due_balance'] ?? 0));
                                @endphp
                                <td class="px-4 py-3 text-right tabular-nums {{ $rowBroughtForward < 0 ? 'text-rose-600' : ($rowBroughtForward > 0 ? 'text-emerald-600' : 'text-slate-500') }}">
                                    @if ($rowBroughtForward > 0)
                                        {{ __('Credit') }} {{ Money::taka(abs($rowBroughtForward)) }}
                                    @elseif ($rowBroughtForward < 0)
                                        {{ __('Owes') }} {{ Money::taka(abs($rowBroughtForward)) }}
                                    @else
                                        {{ Money::taka(0) }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium {{ $rowNet < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                    {{ $rowNet < 0 ? __('Owes') : __('Credit') }} {{ Money::taka(abs($rowNet)) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <p class="mt-3 text-xs text-slate-500">
            @lang('Brought forward = the member\'s net position carried in from before this month (a prior-month advance deposit not yet consumed shows here, not as this-month income). This month = bill, payments, and the resulting due for the current month only. Closing (net) = brought forward + this-month net — the member\'s true running balance.')
        </p>
    @endif
@endsection
