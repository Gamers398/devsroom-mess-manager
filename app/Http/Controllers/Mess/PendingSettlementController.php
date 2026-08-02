<?php

namespace App\Http\Controllers\Mess;

use App\Http\Controllers\Controller;
use App\Models\Mess;
use App\Models\PendingSettlement;
use App\Services\PendingSettlementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manager-facing list of outstanding pending settlements (dues to collect +
 * credits to pay out) and the manual "Mark settled" action for credits.
 * Dues are cleared automatically by payments (FIFO); credits are cleared here.
 */
class PendingSettlementController extends Controller
{
    public function __construct(private readonly PendingSettlementService $service) {}

    public function index(Request $request): View
    {
        $messId = Mess::activeId();
        $kind = $request->query('kind');

        return view('mess.settlements.index', [
            'settlements' => $this->service->outstanding($messId, $kind),
            'totals' => $this->service->outstandingTotals($messId),
            'activeKind' => $kind,
        ]);
    }

    public function markSettled(Request $request, PendingSettlement $settlement): RedirectResponse
    {
        $this->service->markCreditSettledManually(
            $settlement,
            (int) $request->user()->id,
            (string) $request->input('note', ''),
        );

        return redirect()
            ->route('mess.settlements.index')
            ->with('success', __('Pending credit marked as settled.'));
    }
}
