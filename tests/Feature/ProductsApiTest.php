<?php

test('products endpoint returns a product list', function () {
    $response = $this->get('/api/products');

    $response
        ->assertOk()
        ->assertJsonPath('items.0.name', 'react');
});
