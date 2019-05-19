<?php

namespace Backblaze\HttpClient;

interface HttpClientInterface
{
    /**
     * Makes a HTTP request to the specified URL with the specified parameters.
     *
     * @param string $method
     * @param string $url
     * @param string $body
     * @param array  $headers
     *
     * @return array
     */
    public function request(string $method, string $url, string $body = '', array $headers = []): array;
}