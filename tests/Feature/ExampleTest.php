<?php

test('returns a successful response', function () {
    $this->withoutVite();

    $response = $this->get(route('home'));

    $response->assertRedirect(route('election.role-demo.index'));
});
