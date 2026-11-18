<?php

namespace App\Election\Bundle;

use Illuminate\Support\Facades\Auth;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRequest;
use LBHurtado\ElectionCeremonies\Contracts\ActorVerifier;
use RuntimeException;

final class ResearchActorVerifier implements ActorVerifier
{
    public function verify(CeremonyRequest $request): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->research_reviewer || (string) $user->getAuthIdentifier() !== $request->actorId) {
            throw new RuntimeException('A named research reviewer is required.');
        }
    }
}
