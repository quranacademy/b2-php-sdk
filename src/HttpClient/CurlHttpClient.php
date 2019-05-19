<?php

namespace Backblaze\HttpClient;

class CurlHttpClient implements HttpClientInterface
{
    /**
     * Makes a HTTP request to the specified URL with specified parameters.
     *
     * @param string $method
     * @param string $url
     * @param string $body
     * @param array $headers
     *
     * @return array
     */
    public function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        $curlHandle = curl_init();

        $curlOptions = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $this->buildRequestHeaders($headers),
        ];

        curl_setopt_array($curlHandle, $curlOptions);

        $response = curl_exec($curlHandle);
        $response = $this->parseResponse($response, $curlHandle);

        curl_close($curlHandle);

        return $response;
    }

    /**
     * @param array $headers
     *
     * @return array
     */
    private function buildRequestHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $result[] = "{$name}: {$value}";
        }

        return $result;
    }

    /**
     * @param string $response
     * @param resource $curlHandle cURL handle
     *
     * @return array
     */
    private function parseResponse(string $response, $curlHandle): array
    {
        $info = curl_getinfo($curlHandle);
        $headerSize = $info['header_size'];

        $headersString = trim(substr($response, 0, $headerSize));
        $headers = $this->parseHttpHeaders($headersString);

        $body = trim(substr($response, $headerSize));

        return [
            'status_code' => $info['http_code'],
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @param string $headersString
     *
     * @return array
     */
    private function parseHttpHeaders(string $headersString): array
    {
        $result = [];

        $lines = explode("\r\n", $headersString);

        foreach ($lines as $line) {
            if (strpos($line, ': ') === false) {
                continue;
            }

            list($name, $value) = explode(': ', $line);

            $result[$name] = $value;
        }

        return $result;
    }
}
