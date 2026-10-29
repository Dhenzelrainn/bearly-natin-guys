<?php

namespace Tests\Unit;

use App\Support\ImageUrlPolicy;
use Illuminate\Http\Request;
use Tests\TestCase;

class ImageUrlPolicyTest extends TestCase
{
    public function test_localhost_http_url_preserves_its_port_path_and_query(): void
    {
        $this->configurePolicy('local', ['localhost']);

        $this->assertSame(
            'http://localhost:8011/storage/products/bear.jpg?width=320&fit=cover',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://localhost:8011/storage/products/bear.jpg?width=320&fit=cover'),
        );
    }

    public function test_loopback_http_url_is_preserved_in_local_environment(): void
    {
        $this->configurePolicy('local', ['127.0.0.1']);

        $this->assertSame(
            'http://127.0.0.1:8011/storage/products/bear.jpg?variant=small',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://127.0.0.1:8011/storage/products/bear.jpg?variant=small'),
        );
    }

    public function test_only_the_exact_configured_private_lan_ip_is_preserved(): void
    {
        $this->configurePolicy('local', ['192.168.1.42']);

        $this->assertSame(
            'http://192.168.1.42:8011/storage/products/bear.jpg',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://192.168.1.42:8011/storage/products/bear.jpg'),
        );
        $this->assertSame(
            'https://192.168.1.43:8011/storage/products/bear.jpg',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://192.168.1.43:8011/storage/products/bear.jpg'),
        );
    }

    public function test_external_http_hosts_are_upgraded_even_if_added_to_configuration(): void
    {
        $this->configurePolicy('local', ['cdn.example.test']);

        $this->assertSame(
            'https://cdn.example.test/products/bear.jpg?width=320',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://cdn.example.test/products/bear.jpg?width=320'),
        );
    }

    public function test_bearlymarket_domain_and_subdomains_always_use_https(): void
    {
        $this->configurePolicy('local', ['bearlymarket.com', 'images.bearlymarket.com']);

        $this->assertSame(
            'https://bearlymarket.com/storage/products/bear.jpg',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://bearlymarket.com/storage/products/bear.jpg'),
        );
        $this->assertSame(
            'https://images.bearlymarket.com:8443/storage/products/bear.jpg?width=320',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://images.bearlymarket.com:8443/storage/products/bear.jpg?width=320'),
        );
    }

    public function test_production_always_upgrades_approved_local_http_hosts(): void
    {
        $this->configurePolicy('production', ['localhost', '127.0.0.1', '192.168.1.42']);

        $this->assertSame(
            'https://192.168.1.42:8011/storage/products/bear.jpg',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://192.168.1.42:8011/storage/products/bear.jpg'),
        );
    }

    public function test_protocol_relative_urls_use_http_only_for_an_approved_local_host(): void
    {
        $this->configurePolicy('local', ['localhost']);

        $this->assertSame(
            'http://localhost:8011/storage/products/bear.jpg?width=320',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('//localhost:8011/storage/products/bear.jpg?width=320'),
        );
        $this->assertSame(
            'https://cdn.example.test/storage/products/bear.jpg',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('//cdn.example.test/storage/products/bear.jpg'),
        );
    }

    public function test_ipv6_loopback_is_preserved_only_when_explicitly_configured(): void
    {
        $this->configurePolicy('local', ['::1']);

        $this->assertSame(
            'http://[::1]:8011/storage/products/bear.jpg',
            ImageUrlPolicy::enforceHttpsUnlessApprovedLocal('http://[::1]:8011/storage/products/bear.jpg'),
        );
    }

    public function test_local_public_disk_urls_require_the_exact_configured_request_host(): void
    {
        $this->configurePolicy('local', ['192.168.1.42']);
        $request = Request::create('http://192.168.1.42:8011/');
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);

        $this->assertSame(
            'http://192.168.1.42:8011/storage/products/bear.jpg',
            ImageUrlPolicy::localPublicDiskUrl('public', 'products/bear.jpg'),
        );
        $this->assertSame(
            'http://192.168.1.42:8011/storage/products/bear.jpg',
            ImageUrlPolicy::diskPathUrl('public', 'products/bear.jpg'),
        );

        $unlistedRequest = Request::create('http://192.168.1.43:8011/');
        $this->app->instance('request', $unlistedRequest);
        $this->app['url']->setRequest($unlistedRequest);

        $this->assertNull(ImageUrlPolicy::localPublicDiskUrl('public', 'products/bear.jpg'));
        $this->assertNull(ImageUrlPolicy::localPublicDiskUrl('private', 'products/bear.jpg'));

        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
        config(['filesystems.disks.public.driver' => 's3']);
        $this->assertNull(ImageUrlPolicy::localPublicDiskUrl('public', 'products/bear.jpg'));
    }

    public function test_disk_path_urls_use_local_request_host_and_keep_production_https(): void
    {
        $this->configurePolicy('local', ['127.0.0.1']);
        config(['filesystems.disks.public.driver' => 'local']);
        $request = Request::create('http://127.0.0.1:8011/home');
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);

        $this->assertSame(
            'http://127.0.0.1:8011/storage/products/bear.jpg',
            ImageUrlPolicy::diskPathUrl('public', 'products/bear.jpg'),
        );
        $this->assertSame(
            'http://127.0.0.1:8011/storage/products/bear.jpg',
            ImageUrlPolicy::diskPathUrl('public', '/storage/products/bear.jpg'),
        );

        $this->configurePolicy('production', ['127.0.0.1']);
        config([
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.url' => 'http://bearlymarket.com/storage',
        ]);

        $this->assertSame(
            'https://bearlymarket.com/storage/products/bear.jpg',
            ImageUrlPolicy::diskPathUrl('public', 'products/bear.jpg'),
        );
    }

    /** @param list<string> $hosts */
    private function configurePolicy(string $environment, array $hosts): void
    {
        $this->app->detectEnvironment(static fn (): string => $environment);
        config(['image_urls.local_http_hosts' => $hosts]);
    }
}
