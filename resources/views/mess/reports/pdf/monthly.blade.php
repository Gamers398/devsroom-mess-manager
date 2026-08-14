@extends('layouts.pdf')

@section('title', __('Monthly Report') . ' - ' . ($period ?? ''))

{{-- Suppress default header from parent layout to fix double header --}}
@section('header')
@endsection

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

    /* Hide parent layout header elements if rendered outside section */
    .header, header, .pdf-header, .brand-header, .report-header {
        display: none !important;
    }

    /* Center title header in upper middle */
    .report-title-header {
        text-align: center;
        margin-top: 10px;
        margin-bottom: 20px;
        width: 100%;
    }
    .report-title-header h2 {
        margin: 0 0 4px 0;
        font-size: 20px;
        color: #111;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .report-title-header p {
        margin: 0;
        font-size: 12px;
        color: #444;
    }

    /* Summary totals box table */
    .totals-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        background-color: #fafafa;
    }
    .totals-table td {
        padding: 8px 10px;
        font-size: 11px;
        width: 33.33%;
        border: 1px solid #444444; /* Slightly bold defined border */
    }

    /* Main Table Styles */
    .pdf-table-compact {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    .pdf-table-compact th, .pdf-table-compact td {
        border: 1px solid #444444; /* Slightly bold defined border */
        padding: 7px 8px;
        font-size: 11px;
    }
    .pdf-table-compact th {
        background-color: #f0f0f0;
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

<!-- Summary Grid -->
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

<!-- Member Statement Table -->
@if (! empty($members))
    <table class="pdf-table-compact">
        <thead>
            <tr>
                <th style="text-align: left;">{{ __('Member') }}</th>
                <th class="num">{{ __('Meals') }}</th>
                <th class="num">{{ __('Meal cost') }}</th>
                <th class="num">{{ __('Paid') }}</th>
                <th class="num">{{ __('Due') }}</th>
                <th class="num">{{ __('Advance') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($members as $row)
                @php
                    $mealCost = (float) ($row['meal_cost'] ?? $row['bill'] ?? 0);
                    $paid = (float) ($row['bill_payments'] ?? $row['paid'] ?? 0);
                    $broughtForward = (float) ($row['brought_forward'] ?? 0);
                    
                    // Determine Closing (Net) Balance: Positive = Credit, Negative = Owes
                    if (isset($row['closing_net']) && is_numeric($row['closing_net'])) {
                        $closingNet = (float) $row['closing_net'];
                    } elseif (isset($row['closing']) && is_numeric($row['closing'])) {
                        $closingNet = (float) $row['closing'];
                    } else {
                        $closingNet = ($paid - $mealCost) + $broughtForward;
                    }

                    // If net closing is negative -> owes money -> Due
                    // If net closing is positive -> has credit -> Advance
                    $due = $closingNet < 0 ? abs($closingNet) : 0;
                    $advance = $closingNet > 0 ? $closingNet : 0;
                @endphp
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ number_format((float) ($row['meals'] ?? 0), 1) }}</td>
                    <td class="num">{{ $formatTk($mealCost) }}</td>
                    <td class="num">{{ $formatTk($paid) }}</td>
                    
                    <!-- Due (Red if member owes) -->
                    <td class="num">
                        @if ($due > 0)
                            <span class="text-red">{{ $formatTk($due) }}</span>
                        @else
                            {{ $formatTk(0) }}
                        @endif
                    </td>

                    <!-- Advance (Green if member has credit) -->
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