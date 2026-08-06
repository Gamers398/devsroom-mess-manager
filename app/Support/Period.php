<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared year/month period scoping for list pages (Expenses, Payments).
 *
 * Encapsulates the LOCKED filter UX (CONTEXT "Filter UX = Year/Month dropdown,
 * default current month"): both services call Period::apply() right after the
 * query is built so the default (no query params) scope is the current month.
 */
final class Period
{
    public const THIS_MONTH = 'this_month';

    public const MONTH = 'month';

    public const YEAR = 'year';

    public const ALL = 'all';

    public const MODES = [self::THIS_MONTH, self::MONTH, self::YEAR, self::ALL];

    /**
     * Apply the resolved period scope to the query and return the mode used.
     *
     * `period` defaults to THIS_MONTH; anything outside MODES falls back to
     * THIS_MONTH. `year` defaults to the current year; `month` defaults to the
     * current month (clamped 1-12).
     */
    public static function apply(Builder $query, Request $request, string $column = 'date'): string
    {
        $mode = $request->query('period', self::THIS_MONTH);

        if (! in_array($mode, self::MODES, true)) {
            $mode = self::THIS_MONTH;
        }

        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        $month = max(1, min(12, $month));

        return match ($mode) {
            self::THIS_MONTH => tap($mode, fn () => $query->whereYear($column, now()->year)->whereMonth($column, now()->month)),
            self::MONTH => tap($mode, fn () => $query->whereYear($column, $year)->whereMonth($column, $month)),
            self::YEAR => tap($mode, fn () => $query->whereYear($column, $year)),
            self::ALL => $mode, // no date condition
        };
    }

    /**
     * Dropdown [value => label] map for the period selector.
     *
     * @return array<string,string>
     */
    public static function options(): array
    {
        return [
            self::THIS_MONTH => __('This month'),
            self::MONTH => __('Specific month'),
            self::YEAR => __('Whole year'),
            self::ALL => __('All time'),
        ];
    }
}
