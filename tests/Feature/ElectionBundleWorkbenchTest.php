<?php

use Inertia\Testing\AssertableInertia as Assert;

test('bundle workbench shows a verified research overview and filtered precincts', function (): void {
    $this->get(route('election.bundle.index', ['q' => '24020196']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/BundleWorkbench')
            ->where('overview.available', true)
            ->where('overview.bundle.readiness', 'research')
            ->where('overview.report.precinct_count', 93629)
            ->where('browse.total', 1)
            ->where('browse.precincts.0.clustered_precinct', '24020196')
            ->where('browse.precincts.0.legislative_district', '1')
            ->etc());
});

test('bundle workbench drills through region, city, barangay, and precinct evidence', function (): void {
    $this->get(route('election.bundle.index', ['region' => 'BARMM']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/BundleWorkbench')
            ->where('browse.group_level', 'province')
            ->etc());

    $this->get(route('election.bundle.index', ['region' => 'NCR']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/BundleWorkbench')
            ->where('browse.group_level', 'city_municipality')
            ->etc());

    $this->get(route('election.bundle.index', [
        'region' => 'NCR',
        'city_municipality' => 'CITY OF PARAÑAQUE',
        'barangay' => 'BACLARAN',
        'status' => 'source_supported_pending_review',
    ]))->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/BundleWorkbench')
            ->where('browse.group_level', 'clustered_precinct')
            ->where('browse.precincts.0.legislative_district', '1')
            ->etc());

    $this->get(route('election.bundle.precinct.show', '76040001'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/BundlePrecinct')
            ->where('precinct.province', 'NCR - 4TH DISTRICT')
            ->where('precinct.legislative_district', '1')
            ->etc());
});

test('bundle workbench shows pending correction notices without applying them', function (): void {
    $this->get(route('election.bundle.index', ['view' => 'corrections']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/BundleWorkbench')
            ->where('overview.report.unreviewed_correction_count', 4)
            ->where('overview.corrections.0.applied', false)
            ->where('browse', null)
            ->etc());
});

test('bundle workbench never presents missing research outputs as ready', function (): void {
    config()->set('election.bundle_workbench.archive', '/private/tmp/no-such-bundle.zip');

    $this->get(route('election.bundle.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/BundleWorkbench')
            ->where('overview.available', false)
            ->where('browse', null)
            ->etc());

    $this->get(route('election.bundle.precinct.show', '76040001'))->assertNotFound();
});
