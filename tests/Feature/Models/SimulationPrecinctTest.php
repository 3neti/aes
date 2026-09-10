<?php

test('example', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('election.role-demo.index'));
});
