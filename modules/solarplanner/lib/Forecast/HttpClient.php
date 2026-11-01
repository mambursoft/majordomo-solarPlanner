<?php
/**
 * HttpClient — minimal GET client with timeout, retry/backoff and SSRF guard.
 *
 * Security (spec):
 *  - no arbitrary URLs for uncontrolled requests; callers pass a provider base
 *    plus query params, and custom endpoints are validated against an allowlist
 *    of schemes/hosts by the adapter.
 *  - bounded retries with exponential backoff + jitter.
 *  - explicit handling of 429 / 401 / 403 / 5xx surfaced to the caller.
 *
 * PHP 7.3 compatible. Uses curl when available, falls back to streams.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class HttpClient
{
    private $timeout;
    private $maxRetries;
    private $allowPrivate;

    public function __construct($timeout = 15, $maxRetries = 2, $allowPrivate = false)
    {
        $this->timeout = (int)$timeout;
        $this->maxRetries = (int)$maxRetries;
        $this->allowPrivate = (bool)$allowPrivate;
    }

    /**
     * Perform a GET request.
     *
     * @param string $url full URL (https only unless host is private & allowed)
     * @param array $headers
     * @return array ['status'=>int,'body'=>string,'error'=>string|null,'headers'=>array]
     */
    public function get($url, array $headers = array())
    {
        $guard = $this->guardUrl($url);
        if ($guard !== true) {
            return array('status' => 0, 'body' => '', 'error' => $guard, 'headers' => array());
        }

        $attempt = 0;
        $lastErr = null;
        while ($attempt <= $this->maxRetries) {
            $res = $this->doGet($url, $headers);
            $status = $res['status'];
            // Retry only on transient conditions.
            if ($status === 429 || ($status >= 500 && $status < 600) || $status === 0) {
                $lastErr = $res['error'] ?: ('HTTP ' . $status);
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    break;
                }
                // exponential backoff + jitter (ms), capped.
                $base = min(8000, 500 * (2 ** $attempt));
                $jitter = random_int(0, 300);
                usleep(($base + $jitter) * 1000);
                continue;
            }
            return $res;
        }
        return array('status' => 0, 'body' => '', 'error' => $lastErr ?: 'request failed', 'headers' => array());
    }

    /**
     * SSRF guard: require https (or an explicitly allowed private host), reject
     * link-local / loopback / private ranges unless allowPrivate is set.
     *
     * @return true|string true if allowed, otherwise an error message
     */
    public function guardUrl($url)
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return 'invalid url';
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== 'https' && !($this->allowPrivate && $scheme === 'http')) {
            return 'scheme not allowed: ' . $scheme;
        }
        $host = $parts['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $isPrivate = !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($isPrivate && !$this->allowPrivate) {
                return 'private/reserved address blocked by SSRF guard';
            }
        }
        return true;
    }

    private function doGet($url, array $headers)
    {
        if (function_exists('curl_init')) {
            return $this->curlGet($url, $headers);
        }
        return $this->streamGet($url, $headers);
    }

    private function curlGet($url, array $headers)
    {
        $ch = curl_init();
        $hdr = array();
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'majordomo-solarPlanner/1.0',
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        return array('status' => $status, 'body' => $body === false ? '' : $body, 'error' => $err, 'headers' => array());
    }

    private function streamGet($url, array $headers)
    {
        $hdrStr = '';
        foreach ($headers as $k => $v) {
            $hdrStr .= $k . ': ' . $v . "\r\n";
        }
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET',
            'timeout' => $this->timeout,
            'header' => $hdrStr,
            'ignore_errors' => true,
            'user_agent' => 'majordomo-solarPlanner/1.0',
        )));
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        return array(
            'status' => $status,
            'body' => $body === false ? '' : $body,
            'error' => $body === false ? 'stream request failed' : null,
            'headers' => isset($http_response_header) ? $http_response_header : array(),
        );
    }
}
