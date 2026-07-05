<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController extends Controller
{
    public function __invoke(ElectionSnapshot $snapshot): Response
    {
        return Inertia::render('Election/Home', [
            'snapshot' => $snapshot->get(),
        ]);
    }
}
