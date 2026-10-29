<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ImageUrlPolicy
{
    public static function diskPathUrl(string $diskName, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $path) === 1 || str_starts_with($path, '//')) {
            return self::enforceHttpsUnlessApprovedLocal($path);
        }

        $diskConfig = config("filesystems.disks.{$diskName}", []);
        $diskConfig = is_array($diskConfig) ? $diskConfig : [];
        $basePath = trim((string) (parse_url((string) ($diskConfig['url'] ?? ''), PHP_URL_PATH) ?: ''), '/');
        $path = ltrim($path, '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath.'/'))) {
            $path = ltrim(substr($path, strlen($basePath)), '/');
        }

        $url = self::localPublicDiskUrl($diskName, $path)
            ?? Storage::disk($diskName)->url($path);
        if (! Str::startsWith($url, ['http://', 'https://', '//'])) {
            $url = url('/'.ltrim($url, '/'));
        }

        return self::enforceHttpsUnlessApprovedLocal($url);
    }

    public static function localPublicDiskUrl(string $diskName, string $path): ?string
    {
        if ($diskName !== 'public'
            || config('filesystems.disks.public.driver') !== 'local'
            || ! app()->environment('local')) {
            return null;
        }

        $baseUrl = request()->getSchemeAndHttpHost();
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || ! self::isConfiguredLocalHost(self::normalizeHost($host))) {
            return null;
        }

        return self::enforceHttpsUnlessApprovedLocal($baseUrl.'/storage/'.ltrim($path, '/'));
    }

    public static function enforceHttpsUnlessApprovedLocal(string $url): string
    {
        $isProtocolRelative = str_starts_with($url, '//');
        $candidate = $isProtocolRelative ? 'http:'.$url : $url;
        $scheme = parse_url($candidate, PHP_URL_SCHEME);

        if (! is_string($scheme) || strtolower($scheme) !== 'http') {
            return $isProtocolRelative ? 'https:'.$url : $url;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return self::forceHttps($url);
        }

        $host = self::normalizeHost($host);
        if (self::isBearlyMarketHost($host)) {
            return self::forceHttps($url);
        }

        if (app()->environment('local') && self::isConfiguredLocalHost($host)) {
            return $isProtocolRelative ? 'http:'.$url : $url;
        }

        return self::forceHttps($url);
    }

    private static function isConfiguredLocalHost(string $host): bool
    {
        $configuredHosts = config('image_urls.local_http_hosts', []);
        if (! is_array($configuredHosts)) {
            return false;
        }

        foreach ($configuredHosts as $configuredHost) {
            if (! is_string($configuredHost)) {
                continue;
            }

            $configuredHost = self::normalizeHost(trim($configuredHost));
            if (($configuredHost === 'localhost' || self::isPrivateOrLoopbackIp($configuredHost))
                && $configuredHost === $host) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateOrLoopbackIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = array_map('intval', explode('.', $host));

            return $octets[0] === 10
                || $octets[0] === 127
                || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
                || ($octets[0] === 192 && $octets[1] === 168);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($host);

            return $packed === inet_pton('::1')
                || (ord($packed[0]) & 0xfe) === 0xfc;
        }

        return false;
    }

    private static function isBearlyMarketHost(string $host): bool
    {
        return $host === 'bearlymarket.com'
            || str_ends_with($host, '.bearlymarket.com');
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host, '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return strtolower(inet_ntop(inet_pton($host)) ?: $host);
        }

        return $host;
    }

    private static function forceHttps(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        return preg_replace('~^http://~i', 'https://', $url) ?: $url;
    }
}
