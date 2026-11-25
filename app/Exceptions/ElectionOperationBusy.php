<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ElectionOperationBusy extends Exception
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 503, ['Retry-After' => '5']);
        }

        $method = $request->route()?->getActionMethod();
        if (in_array($method, ['submitTallySheet', 'submitLatestControlNumberReceipt', 'submitScopedElectionReturn'], true)) {
            return back()->with('role_demo.closeout_feedback', $this->getMessage());
        }

        $field = match ($method) {
            'claim', 'redeem', 'acceptPrint' => 'code',
            'finalize' => 'selections',
            'joinQueue' => 'queue',
            'print' => 'printer',
            'deposit' => 'deposit',
            'reprintVvpat', 'confirmVvpat', 'reconcileVvpat' => 'vvpat',
            default => 'officer_pin',
        };

        return back()->withErrors([$field => $this->getMessage()]);
    }
}
