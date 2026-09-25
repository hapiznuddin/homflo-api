<?php

describe('CORS for SPA', function () {
    test('api response carries spa origin and credentials headers', function () {
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])->getJson('/api/me');

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:3000');
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
    });

    test('preflight request is answered for spa origin', function () {
        $response = $this->call(
            'OPTIONS',
            '/api/me',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'http://localhost:3000',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]
        );

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:3000');
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
    });

    test('wildcard origin is never used with credentials', function () {
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])->getJson('/api/me');

        expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
    });

    test('unknown origin never matches the returned origin', function () {
        $response = $this->call(
            'OPTIONS',
            '/api/me',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://attacker.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]
        );

        // Browsers reject the response unless the returned origin matches
        // the requesting origin, so a mismatch blocks credentialed access.
        expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://attacker.example');
    });
});
