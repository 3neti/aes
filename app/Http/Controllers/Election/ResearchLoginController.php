<?php

namespace App\Http\Controllers\Election;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ResearchLoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Election/ResearchLogin');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'research_reviewer' => true])) {
            throw ValidationException::withMessages(['email' => 'These reviewer credentials are invalid.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('election.bundle.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('election.bundle.index');
    }
}
