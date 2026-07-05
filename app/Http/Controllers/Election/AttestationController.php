<?php

namespace App\Http\Controllers\Election;

use App\Election\Attestation\OfficerAttestationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOfficerAttestationRequest;
use Illuminate\Http\RedirectResponse;

final class AttestationController extends Controller
{
    public function store(StoreOfficerAttestationRequest $request, OfficerAttestationService $attestations): RedirectResponse
    {
        $attestations->attest($request->validated());

        return redirect()->back();
    }
}
