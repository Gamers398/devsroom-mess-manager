@extends('layouts.app')
@section('content')
    @php
        use App\Support\Money;
        use Carbon\Carbon;

        $toCollect = (float) ($totals['to_collect'] ?? 0);
        $toPayOut = (float) ($totals['to_pay_out'] ?? 0);
        $net = (float) ($totals['net'] ?? 0);
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold leading-tight text-slate-900">{{ __('Pending settlements') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-600">
            {{ __('Outstanding dues (members owe the mess) and credits (the mess owes members) carried from closed months. Dues clear automatically when a payment is recorded; credits clear when you mark them settled after refunding the member.') }}
        </p>
    </header>

    <section class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-stat-card
            :label="__('To collect (dues)')"
            :value="Money::taka($toCollect)"
            :hint="__('Owed by members')" />

        <x-stat-card
            :label="__('To pay out (credits)')"
            :value="Money::taka($toPayOut)"
            :hint="__('Owed to members')" />

        <x-stat-card
            :label="__('Net cash to handle')"
            :value="Money::taka($net)"
            :hint="__('Collect − pay out')" />
    </section>

    {{-- Filter pills --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <a href="{{ route('mess.settlements.index') }}"
           class="rounded-full border px-3 py-1 text-sm {{ $activeKind === null ? 'border-emerald-600 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' }}">
            {{ __('All') }}
        </a>
        <a href="{{ route('mess.settlements.index', ['kind' => 'due']) }}"
           class="rounded-full border px-3 py-1 text-sm {{ $activeKind === 'due' ? 'border-rose-600 bg-rose-50 text-rose-700' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' }}">
            {{ __('Dues') }}
        </a>
        <a href="{{ route('mess.settlements.index', ['kind' => 'credit']) }}"
           class="rounded-full border px-3 py-1 text-sm {{ $activeKind === 'credit' ? 'border-emerald-600 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' }}">
            {{ __('Credits') }}
        </a>
    </div>

    @if ($settlements->isEmpty())
        <x-empty-state
            :title="__('Nothing pending.')"
            :description="__('All dues and credits from closed months are settled. 🎉')" />
    @else
        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Member') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Kind') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Source') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Original') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Settled') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Remaining') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @php $cursor = null; @endphp
                        @foreach ($settlements as $s)
                            @php
                                $remaining = (float) $s->amountRemaining();
                                $monthKey = $s->source_year.'-'.str_pad((string) $s->source_month, 2, '0', STR_PAD_LEFT);
                                $showGroupHeader = $cursor !== $monthKey;
                                $cursor = $monthKey;
                            @endphp
                            @if ($showGroupHeader)
                                <tr class="bg-slate-50/60">
                                    <td colspan="8" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {{ Carbon::create($s->source_year, $s->source_month, 1)->translatedFormat('F Y') }}
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap font-medium text-slate-900">
                                    <a href="{{ route('mess.members.wallet', $s->member_id) }}" class="hover:text-emerald-700 hover:underline">{{ $s->member?->name }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($s->kind === 'due')
                                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">{{ __('Due') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Credit') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500">
                                    @if ($s->source === 'correction'){{ __('Correction') }}@else{{ __('Close') }}@endif
                                    @if ($s->sourceClosing)
                                        <a href="{{ route('mess.closings.show', $s->sourceClosing) }}" class="ml-1 text-emerald-700 hover:underline">#{{ $s->sourceClosing->id }}</a>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ Money::taka((float) $s->original_amount) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ Money::taka((float) $s->amount_settled) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold {{ $s->kind === 'due' ? 'text-rose-700' : 'text-emerald-700' }}">{{ Money::taka($remaining) }}</td>
                                <td class="px-4 py-3">
                                    @if ($s->status === 'pending')
                                        <span class="text-xs font-medium text-amber-700">{{ __('Pending') }}</span>
                                    @elseif ($s->status === 'partial')
                                        <span class="text-xs font-medium text-sky-700">{{ __('Partial') }}</span>
                                    @else
                                        <span class="text-xs font-medium text-slate-400">{{ __('Settled') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($s->kind === 'credit' && $remaining > 0)
                                        <form method="POST" action="{{ route('mess.settlements.markSettled', $s) }}" class="inline-flex items-center gap-1"
                                              onsubmit="return confirm('{{ __('Mark this credit as settled? The member\'s balance will be reduced by the remaining amount.') }}');">
                                            @csrf
                                            <input type="text" name="note" maxlength="200" placeholder="{{ __('note (optional)') }}"
                                                   class="input w-40 text-xs" />
                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Mark settled') }}</button>
                                        </form>
                                    @elseif ($s->kind === 'due' && $remaining > 0)
                                        <a href="{{ route('mess.payments.create', ['member_id' => $s->member_id]) }}"
                                           class="btn btn-sm btn-primary"
                                           title="{{ __('Record a payment — it will auto-settle this due (oldest first).') }}">
                                            {{ __('Record payment') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
