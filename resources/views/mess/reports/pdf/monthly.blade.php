@extends('layouts.pdf')

@section('title', __('Monthly Report') . ' — ' . ($period ?? ''))

@section('report-body')
<style>
    /* Fix missing Taka symbol by forcing DejaVu Sans font */
    * {
        font-family: 'DejaVu Sans', sans-serif !important;
    }
    
    /* Layout table for the summary stats */
    .totals-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
        background-color: #f8f9fa;
        border: 1px solid #e9ecef;
    }
    .totals-table td {
        padding: 6px 10px;
        font-size: 11px;
        width: 33.33%;
    }
</style>
    @php
        use App\Support\Money;
        $members = $data['members'] ?? [];
        $totalDue = collect($members)->sum('due');
        $totalAdvance = collect($members)->sum('advance_balance');
    @endphp

    <table class="totals-table">
    <tr>
        <td><strong>{{ __('Members') }}:</strong> {{ count($members) }}</td>
        <td><strong>{{ __('Meals') }}:</strong> {{ number_format((float) ($data['total_meals'] ?? 0), 1) }}</td>
        <td><strong>{{ __('Meal rate') }}:</strong> {{ Money::taka($data['meal_rate'] ?? 0) }} / meal</td>
    </tr>
    <tr>
        <td><strong>{{ __('Total bazar') }}:</strong> {{ Money::taka($data['total_bazar'] ?? 0) }}</td>
        <td><strong>{{ __('Total fixed') }}:</strong> {{ Money::taka($data['total_fixed'] ?? 0) }}</td>
        <td>
            @php $pdfNet = collect($members)->sum(fn ($r) => ($r['advance_balance'] ?? 0) - ($r['due_balance'] ?? 0)); @endphp
            <strong>{{ __('Balance (net)') }}:</strong> {{ ($pdfNet < 0 ? __('Owes') : __('Credit')) . ' ' . Money::taka(abs($pdfNet)) }}
        </td>
    </tr>
</table>

    @if (! empty($members))
        {{-- D-13: column compaction via pdf-table-compact --}}
        <table class="pdf-table-compact">
            <thead>
                <tr>
                    <th>{{ __('Member') }}</th>
                    <th class="num">{{ __('Meals') }}</th>
                    <th class="num">{{ __('Meal cost') }}</th>
                    <th class="num">{{ __('Fixed') }}</th>
                    <th class="num">{{ __('Bill') }}</th>
                    <th class="num">{{ __('Paid') }}</th>
                    <th class="num">{{ __('Due') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($members as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td class="num">{{ number_format((float) $row['meals'], 1) }}</td>
                        <td class="num">{{ Money::taka($row['meal_cost']) }}</td>
                        <td class="num">{{ Money::taka($row['fixed_share']) }}</td>
                        <td class="num">{{ Money::taka($row['bill']) }}</td>
                        <td class="num">{{ Money::taka($row['bill_payments']) }}</td>
                        <td class="num">{{ Money::taka($row['due']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
