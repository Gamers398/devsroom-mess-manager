@extends('layouts.pdf')

@section('title', __('Monthly Report') . ' — ' . ($period ?? ''))

@section('report-body')
@php
    $members = $data['members'] ?? [];

    // Currency helper for PDF
    $formatTk = function($amount) {
        return 'Tk. ' . number_format((float) ($amount ?? 0), 2);
    };

    // Calculate total payments across all members
    $totalPayments = collect($members)->sum(fn ($m) => (float) ($m['bill_payments'] ?? $m['paid'] ?? 0));
@endphp

<style>
    /* Force clean font */
    * {
        font-family: 'DejaVu Sans', sans-serif !important;
    }

    /* Center title header in upper middle */
    .report-title-header {
        text-align: center;
        margin-bottom: 25px;
        width: 100%;
    }
    .report-title-header h2 {
        margin: 0 0 5px 0;
        font-size: 20px;
        color: #111;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .report-title-header p {
        margin: 0;
        font-size: 13px;
        color: #555;
    }

    /* Summary totals grid table */
    .totals-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
    }
    .totals-table td {
        padding: 8px 12px;
        font-size: 11px;
        width: 33.33%;
        border: 1px solid #dee2e6;
    }

    /* Main Table Styles */
    .pdf-table-compact {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    .pdf-table-compact th, .pdf-table-compact td {
        border: 1px solid #dee2e6;
        padding: 7px 8px;
        font-size: 11px;
    }
    .pdf-table-compact th {
        background-color: #f1f3f5;
        font-weight: bold;
        text-align: center;
    }
    .pdf-table-compact td.num {
        text-align: right;
    }

    /* Color Helpers */
    .text-red {
        color: #dc3545;
        font-weight: bold;
    }
    .text-green {
        color: #198754;
        font-weight: bold;
    }
</style>

<!-- Upper Middle Header -->
<div class="report-title-header">
    <h2>Officer's Mess</h2>
    <p>Monthly Report — {{ $data['month_name'] ?? 'August' }} {{ $data['year'] ?? '2026' }}</p>
</div>

<!-- Summary Grid (Total Payments replaces Total Fixed) -->
<table class="totals-table">
    <tr>
        <td><strong>{{ __('Members') }}:</strong> {{ count($members) }}</td>
        <td><strong>{{ __('Meals') }}:</strong> {{ number_format((float) ($data['total_meals'] ?? 0), 1) }}</td>
        <td><strong>{{ __('Meal rate') }}:</strong> {{ $formatTk($data['meal_rate'] ?? 0) }} / meal</td>
    </tr>
    <tr>
        <td><strong>{{ __('Total bazar') }}:</strong> {{ $formatTk($data['total_bazar'] ?? 0) }}</td>
        <td><strong>{{ __('Total Payments') }}:</strong> {{ $formatTk($totalPayments) }}</td>
        <td>
            @php 
                $pdfNet = collect($members)->sum(fn ($r) => ((float)($r['bill_payments'] ?? $r['paid'] ?? 0)) - ((float)($r['meal_cost'] ?? 0)));
            @endphp
            <strong>{{ __('Balance (net)') }}:</strong> {{ ($pdfNet < 0 ? __('Owes') : __('Credit')) . ' ' . $formatTk(abs($pdfNet)) }}
        </td>
    </tr>
</table>

<!-- Member Statement Table (Fixed & Bill columns removed, Next month advance added) -->
@if (! empty($members))
    <table class="pdf-table-compact">
        <thead>
            <tr>
                <th style="text-align: left;">{{ __('Member') }}</th>
                <th class="num">{{ __('Meals') }}</th>
                <th class="num">{{ __('Meal cost') }}</th>
                <th class="num">{{ __('Paid') }}</th>
                <th class="num">{{ __('Due') }}</th>
                <th class="num">{{ __('Next month advance') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($members as $row)
                @php
                    $mealCost = (float) ($row['meal_cost'] ?? 0);
                    $paid = (float) ($row['bill_payments'] ?? $row['paid'] ?? 0);
                    
                    // Calculations
                    $due = max(0, $mealCost - $paid);
                    $advance = max(0, $paid - $mealCost);
                @endphp
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ number_format((float) $row['meals'], 1) }}</td>
                    <td class="num">{{ $formatTk($mealCost) }}</td>
                    <td class="num">{{ $formatTk($paid) }}</td>
                    
                    <!-- Due (Red if > 0) -->
                    <td class="num">
                        @if ($due > 0)
                            <span class="text-red">{{ $formatTk($due) }}</span>
                        @else
                            {{ $formatTk(0) }}
                        @endif
                    </td>

                    <!-- Next month advance (Green if > 0) -->
                    <td class="num">
                        @if ($advance > 0)
                            <span class="text-green">{{ $formatTk($advance) }}</span>
                        @else
                            {{ $formatTk(0) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endsection
