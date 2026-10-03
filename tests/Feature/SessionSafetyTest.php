<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class SessionSafetyTest extends TestCase
{
    public function test_auth_pages_are_not_cached_by_the_browser(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
    }

    public function test_stale_json_csrf_errors_keep_a_json_419_response(): void
    {
        $request = Request::create(
            '/login',
            'POST',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response = $this->app
            ->make(ExceptionHandler::class)
            ->render($request, new TokenMismatchException());

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame(
            'CSRF_TOKEN_MISMATCH',
            json_decode((string) $response->getContent(), true)['code']
        );
    }

    public function test_stale_html_csrf_errors_redirect_to_a_fresh_page(): void
    {
        $request = Request::create('/login', 'POST');
        $request->headers->set('referer', 'http://localhost/login');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->start();

        $response = $this->app
            ->make(ExceptionHandler::class)
            ->render($request, new TokenMismatchException());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            route('login'),
            $response->headers->get('Location')
        );
        $this->assertTrue($request->session()->has('errors'));
    }
}
