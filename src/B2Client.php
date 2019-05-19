<?php

namespace Backblaze;

use Backblaze\Exceptions\Api\B2ApiException;
use Backblaze\Exceptions\Api\BadJsonException;
use Backblaze\Exceptions\Api\BadValueException;
use Backblaze\Exceptions\Api\BucketAlreadyExistsException;
use Backblaze\Exceptions\Api\BucketNotEmptyException;
use Backblaze\Exceptions\Api\FileNotPresentException;
use Backblaze\Exceptions\Api\NotFoundException;
use Backblaze\Exceptions\InvalidResponse;
use Backblaze\Exceptions\UnauthorizedException;
use Backblaze\Exceptions\InvalidOptionsException;
use Backblaze\HttpClient\HttpClientInterface;

class B2Client
{
    public const BUCKET_TYPE_PUBLIC = 'allPublic';
    public const BUCKET_TYPE_PRIVATE = 'allPrivate';

    /**
     * @var string|null
     */
    protected $accountId;

    /**
     * @var string|null
     */
    protected $applicationKey;

    /**
     * @var string|null
     */
    protected $authorizationToken;

    /**
     * @var string|null
     */
    protected $apiUrl;

    /**
     * @var string|null
     */
    protected $downloadUrl;

    /**
     * @var HttpClientInterface
     */
    protected $httpClient;

    /**
     * Constructor.
     *
     * @param HttpClientInterface $httpClient
     */
    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Authorize the B2 account in order to get an auth token and API/download URLs.
     *
     * @param string $accountId
     * @param string $applicationKey
     */
    public function authorize(string $accountId, string $applicationKey): void
    {
        $this->accountId = $accountId;
        $this->applicationKey = $applicationKey;

        $url = 'https://api.backblazeb2.com/b2api/v1/b2_authorize_account';

        $credentials = base64_encode("{$accountId}:{$applicationKey}");

        $headers = [
            'Authorization' => 'Basic ' . $credentials,
        ];

        $response = $this->httpClient->request('GET', $url, '', $headers);

        $responseBody = json_decode($response['body'], true);

        if ($response['status_code'] !== 200) {
            $this->handleErrorResponse($responseBody);
        }

        $this->authorizationToken = $responseBody['authorizationToken'];
        $this->apiUrl = $responseBody['apiUrl'] . '/b2api/v1';
        $this->downloadUrl = $responseBody['downloadUrl'];
    }

    /**
     * @throws UnauthorizedException
     */
    private function checkAuthorization(): void
    {
        if ($this->authorizationToken === null) {
            throw new UnauthorizedException('Please authorize before performing requests to Backblaze B2 API');
        }
    }

    /**
     * Perform a request to the B2 API.
     *
     * @param string $method
     * @param string$endpoint
     * @param array $parameters
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function request(string $method, string $endpoint, array $parameters): array
    {
        $this->checkAuthorization();

        $headers = [
            'Authorization' => $this->authorizationToken,
        ];

        $url = $this->apiUrl . $endpoint;

        $response = $this->httpClient->request($method, $url, json_encode($parameters), $headers);

        $responseBody = json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidResponse('B2 API returned not valid JSON response');
        }

        if ($response['status_code'] !== 200) {
            $this->handleErrorResponse($responseBody);
        }

        return $responseBody;
    }

    /**
     * @param array $response
     */
    protected function handleErrorResponse(array $response): void
    {
        $mappings = [
            'bad_json' => BadJsonException::class,
            'bad_value' => BadValueException::class,
            'duplicate_bucket_name' => BucketAlreadyExistsException::class,
            'not_found' => NotFoundException::class,
            'file_not_present' => FileNotPresentException::class,
            'cannot_delete_non_empty_bucket' => BucketNotEmptyException::class
        ];

        if (array_key_exists($response['code'], $mappings)) {
            $exceptionClass = $mappings[$response['code']];
        } else {
            // if we don't have an exception mapped to this error code throw the generic exception
            $exceptionClass = B2ApiException::class;
        }

        throw new $exceptionClass('Received error from B2: ' . $response['message']);
    }

    /**
     * Create a bucket.
     *
     * @param array $options
     *
     * @return array
     *
     * @throws InvalidOptionsException
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function createBucket(array $options): array
    {
        if ( ! in_array($options['BucketType'], [self::BUCKET_TYPE_PUBLIC, self::BUCKET_TYPE_PRIVATE], true)) {
            throw new InvalidOptionsException(
                sprintf('Bucket type must be "%s" or "%s"', self::BUCKET_TYPE_PUBLIC, self::BUCKET_TYPE_PRIVATE)
            );
        }

        return $this->request('POST', '/b2_create_bucket', [
            'accountId' => $this->accountId,
            'bucketName' => $options['BucketName'],
            'bucketType' => $options['BucketType'],
        ]);
    }

    /**
     * Update a bucket by its ID.
     *
     * @param array $options
     *
     * @return array
     *
     * @throws InvalidOptionsException
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function updateBucket(array $options): array
    {
        if ( ! in_array($options['BucketType'], [self::BUCKET_TYPE_PUBLIC, self::BUCKET_TYPE_PRIVATE], true)) {
            throw new InvalidOptionsException(
                sprintf('Bucket type must be %s or %s', self::BUCKET_TYPE_PUBLIC, self::BUCKET_TYPE_PRIVATE)
            );
        }

        return $this->request('POST', '/b2_update_bucket', [
            'accountId' => $this->accountId,
            'bucketId' => $options['BucketId'],
            'bucketType' => $options['BucketType']
        ]);
    }

    /**
     * Retrieve a list of buckets on the account.
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function listBuckets(): array
    {
        return $this->request('POST', '/b2_list_buckets', [
            'accountId' => $this->accountId
        ]);
    }

    /**
     * Delete a bucket by its ID.
     *
     * @param array $options
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function deleteBucket(array $options): array
    {
        return $this->request('POST', '/b2_delete_bucket', [
            'accountId' => $this->accountId,
            'bucketId' => $options['BucketId'],
        ]);
    }

    /**
     * Get a bucket ID by its name.
     *
     * @param string $bucketName
     *
     * @return string|null
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function getBucketIdByName(string $bucketName): ?string
    {
        $response = $this->listBuckets();

        foreach ($response['buckets'] as $bucket) {
            if ($bucket['bucketName'] === $bucketName) {
                return $bucket['bucketId'];
            }
        }

        return null;
    }

    /**
     * Get a bucket name by its ID.
     *
     * @param string $bucketId
     *
     * @return string|null
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function getBucketNameById(string $bucketId): ?string
    {
        $response = $this->listBuckets();

        foreach ($response['buckets'] as $bucket) {
            if ($bucket['bucketId'] === $bucketId) {
                return $bucket['bucketName'];
            }
        }

        return null;
    }

    /**
     * Uploads a file to a bucket and and returns the information about the uploaded file.
     *
     * @param array $options
     *
     * @return array
     *
     * @throws InvalidOptionsException
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function upload(array $options): array
    {
        $this->checkAuthorization();

        if ( ! array_key_exists('SourceFile', $options) && ! array_key_exists('Body', $options)) {
            throw new InvalidOptionsException('"SourceFile" or "Body" option is required');
        }

        if (array_key_exists('SourceFile', $options) && array_key_exists('Body', $options)) {
            throw new InvalidOptionsException('"SourceFile" and "Body" options must not be present in the same time');
        }

        // clean the path if it starts with "/"
        if (substr($options['FileName'], 0, 1) === '/') {
            $options['FileName'] = ltrim($options['FileName'], '/');
        }

        // retrieve the URL that we should be uploading to.
        $response = $this->request('POST', '/b2_get_upload_url', [
            'bucketId' => $options['BucketId'],
        ]);

        $uploadEndpoint = $response['uploadUrl'];
        $uploadAuthToken = $response['authorizationToken'];

        if (array_key_exists('SourceFile', $options)) {
            $body = file_get_contents($options['SourceFile']);
        }

        if (array_key_exists('Body', $options)) {
            if (is_resource($options['Body'])) {
                // rewind the stream before read
                rewind($options['Body']);

                $body = stream_get_contents($options['Body']);
            } else {
                // we've been given a simple string body, it's super simple to calculate the hash and size
                $body = $options['Body'];
            }
        }

        $hash = sha1($body);
        $size = mb_strlen($body);

        if ( ! array_key_exists('FileLastModified', $options)) {
            $options['FileLastModified'] = round(microtime(true) * 1000);
        }

        if ( ! array_key_exists('FileContentType', $options)) {
            $options['FileContentType'] = 'b2/x-auto';
        }

        $uploadResponse = $this->httpClient->request('POST', $uploadEndpoint, $body, [
            'Authorization' => $uploadAuthToken,
            'Content-Type' => $options['FileContentType'],
            'Content-Length' => $size,
            'X-Bz-File-Name' => $options['FileName'],
            'X-Bz-Content-Sha1' => $hash,
            'X-Bz-Info-src_last_modified_millis' => $options['FileLastModified']
        ]);

        return json_decode($uploadResponse['body'], true);
    }

    /**
     * Download a file from a bucket by the file ID.
     *
     * @param array $options
     *
     * @throws UnauthorizedException
     */
    public function download(array $options): void
    {
        $content = $this->getFileContent($options);

        file_put_contents($options['SaveAs'], $content);
    }

    /**
     * Download a file from B2 by the name of the file and a bucket.
     *
     * @param array $options
     *
     * @throws UnauthorizedException
     */
    public function downloadByName(array $options): void
    {
        $content = $this->getFileContentByName($options);

        file_put_contents($options['SaveAs'], $content);
    }

    /**
     * Retrieve the content of a file stored in B2 by the file ID.
     *
     * @param array $options
     *
     * @return mixed
     *
     * @throws UnauthorizedException
     */
    public function getFileContent(array $options)
    {
        $this->checkAuthorization();

        $requestUrl = $this->downloadUrl . '/b2api/v1/b2_download_file_by_id';

        $queryString = http_build_query([
            'fileId' => $options['FileId'],
        ]);

        $response = $this->httpClient->request('GET', $requestUrl . '?' . $queryString, '', [
            'Authorization' => $this->authorizationToken,
        ]);

        if ($response['status_code'] !== 200) {
            $this->handleErrorResponse(json_decode($response['body'], true));
        }

        return $response['body'];
    }

    /**
     * Retrieve the content of a file stored in B2 by the name of the file and a bucket.
     *
     * @param array $options
     *
     * @return mixed
     *
     * @throws UnauthorizedException
     */
    public function getFileContentByName(array $options)
    {
        $this->checkAuthorization();

        $requestUrl = sprintf('%s/file/%s/%s', $this->downloadUrl, $options['BucketName'], $options['FileName']);

        $response = $this->httpClient->request('GET', $requestUrl, '', [
            'Authorization' => $this->authorizationToken,
        ]);

        if ($response['status_code'] !== 200) {
            $this->handleErrorResponse(json_decode($response['body'], true));
        }

        return $response['body'];
    }

    /**
     * Retrieve a list of files in a bucket.
     *
     * @param array $options
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function listFiles(array $options): array
    {
        $startFileName = null;
        $maxFileCount = 1000;

        if (array_key_exists('StartFileName', $options)) {
            $startFileName = $options['StartFileName'];
        }

        if (array_key_exists('MaxFileCount', $options)) {
            $maxFileCount = $options['MaxFileCount'];
        }

        return $this->request('POST', '/b2_list_file_names', [
            'bucketId' => $options['BucketId'],
            'startFileName' => $startFileName,
            'maxFileCount' => $maxFileCount,
        ]);
    }

    /**
     * Test whether a file exists in a bucket.
     *
     * @param array $options
     *
     * @return bool
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function fileExists(array $options): bool
    {
        $files = $this->listFiles([
            'BucketId' => $options['BucketId'],
            'StartFileName' => $options['FileName'],
            'MaxFileCount' => 1,
        ]);

        return count($files) > 0;
    }

    /**
     * Retrieve the information about a file stored in B2.
     *
     * @param array $options
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function getFileInfo(array $options): array
    {
        return $this->request('POST', '/b2_get_file_info', [
            'fileId' => $options['FileId'],
        ]);
    }

    /**
     * Delete a file from B2.
     *
     * @param array $options
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidResponse
     */
    public function deleteFile(array $options): array
    {
        return $this->request('POST', '/b2_delete_file_version', [
            'fileName' => $options['FileName'],
            'fileId' => $options['FileId'],
        ]);
    }

    /**
     * @return string|null
     */
    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    /**
     * @return string|null
     */
    public function getApplicationKey(): ?string
    {
        return $this->applicationKey;
    }

    /**
     * @return string|null
     */
    public function getAuthorizationToken(): ?string
    {
        return $this->authorizationToken;
    }

    /**
     * @return string|null
     */
    public function getApiUrl(): ?string
    {
        return $this->apiUrl;
    }

    /**
     * @return string|null
     */
    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    /**
     * @return HttpClientInterface
     */
    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }
}
