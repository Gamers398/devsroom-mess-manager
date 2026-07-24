@php
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $current = (float) ($ledger['current_balance'] ?? 0);
    $pending = (float) ($ledger['pending_bill'] ?? 0);
@endphp

<header class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold leading-tight text-slate-900">{{ $member->name }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Wallet') }}</p>
    </div>
    <div class="rounded-xl border p-4 text-right {{ $current < 0 ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50' }}">
        <p class="text-xs uppercase tracking-wide {{ $current < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ $current < 0 ? __('Owes') : __('Credit') }}</p>
        <p class="mt-1 text-2xl font-bold {{ $current < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ Money::taka(abs($current)) }}</p>
    </div>
</header>

@php
    $ms = $ledger['month_summary'] ?? null;
    $applied = $ms ? ((float) ($ms['bill_payments']) + (float) ($ms['advance_applied'])) : 0.0;
    $due = $ms ? (float) ($ms['due']) : 0.0;
@endphp

@if ($ms && ((float) $ms['bill']) > 0)
    {{-- Reconciled current-month summary: Bill − payments − advance applied = Due.
         This is the single number members asked for — it shows the advance
         deposit actually offsetting the bill, instead of a disconnected Credit
         header next to a raw pending bill. --}}
    <section class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('This month') }}</h2>
            <span class="text-xs text-slate-500">{{ __('Bill − payments − advance applied = due · settles at month close') }}</span>
        </div>
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div>
                <dt class="text-xs text-slate-500">{{ __('Bill') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ Money::taka((float) $ms['bill']) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">{{ __('Paid') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ Money::taka((float) $ms['bill_payments']) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">{{ __('Advance applied') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold text-emerald-700">{{ Money::taka((float) $ms['advance_applied']) }}</dd>
            </div>
            <div>
                <dt class="text-xs {{ $due > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ $due > 0 ? __('Due') : __('No due') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold {{ $due > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ Money::taka($due) }}</dd>
            </div>
        </dl>
        <p class="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
            @lang(':meals meals · meal cost :meal_cost.', [
                'meals' => number_format((float) $ms['meals'], 2),
                'meal_cost' => Money::taka((float) $ms['meal_cost']),
            ])
        </p>
    </section>
@elseif ($pending > 0)
    <p class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800">
        {{ __('Current month bill (pending): :amount — not deducted until the month closes.', ['amount' => Money::taka($pending)]) }}
    </p>
@endif

@if ($ledger['rows']->isEmpty())
    <x-empty-state
        :title="__('No activity yet.')"
        :description="__('Payments, monthly bills, and adjustments will appear here.')" />
@else
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Description') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Debit') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($ledger['rows'] as $r)
                        <tr class="{{ ! empty($r['pending']) ? 'bg-amber-50/60' : '' }}">
                            <td class="px-4 py-3 whitespace-nowrap text-slate-700">
                                {{ $r['date'] ? Carbon::parse($r['date'])->format('d-m-Y') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-900">{{ $r['description'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ ! empty($r['debit']) ? Money::taka($r['debit']) : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ ! empty($r['credit']) ? Money::taka($r['credit']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <p class="mt-3 text-xs text-slate-500">{{ __('Credit = money in (payments, advance deposits, credits). Debit = money out (monthly bills, charges). The balance above is your settled position across all closed months.') }}</p>
@endif
