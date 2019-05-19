<?php

namespace Backblaze\Tests;

use Backblaze\B2Client;
use Backblaze\Exceptions\Api\BadValueException;
use Backblaze\Exceptions\Api\BucketNotEmptyException;
use Backblaze\Exceptions\Api\NotFoundException;
use Backblaze\Exceptions\Api\BucketAlreadyExistsException;
use Backblaze\Exceptions\Api\BadJsonException;
use Backblaze\Exceptions\InvalidOptionsException;
use Backblaze\Exceptions\InvalidResponse;
use Backblaze\Exceptions\UnauthorizedException;
use Backblaze\HttpClient\HttpClientInterface;
use Mockery;
use PHPUnit\Framework\TestCase;

class B2ClientTest extends TestCase
{
    private const ACCOUNT_ID = 'testId';
    private const APPLICATION_ID = 'testKey';

    public function testAuthorize(): void
    {
        $httpClient = $this->getHttpClientMock();

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->assertEquals(self::ACCOUNT_ID, $client->getAccountId());
        $this->assertEquals(self::APPLICATION_ID, $client->getApplicationKey());
        $this->assertEquals('https://api900.backblaze.com/b2api/v1', $client->getApiUrl());
        $this->assertEquals('testAuthToken', $client->getAuthorizationToken());
        $this->assertEquals('https://f900.backblaze.com', $client->getDownloadUrl());
    }

    public function testRequest(): void
    {
        $httpClient = $this->getHttpClientMock();

        $parameters = [
            'fileId' => 'foo',
            'bucketId' => 'bar',
        ];

        $expectedResponse = [
            'foo' => 'bar',
        ];

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'POST',
                'https://api900.backblaze.com/b2api/v1/endpoint',
                json_encode($parameters),
                [
                    'Authorization' => 'testAuthToken',
                ],
            ])
            ->once()
            ->andReturn([
                'status_code' => 200,
                'body' => json_encode($expectedResponse),
                'headers' => '',
            ])
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->request('POST', '/endpoint', $parameters);

        $this->assertEquals($expectedResponse, $response);
    }

    public function testRequestWithInvalidJsonResponse(): void
    {
        $httpClient = $this->getHttpClientMock();

        $parameters = [
            'fileId' => 'foo',
            'bucketId' => 'bar',
        ];

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'POST',
                'https://api900.backblaze.com/b2api/v1/endpoint',
                json_encode($parameters),
                [
                    'Authorization' => 'testAuthToken',
                ],
            ])
            ->once()
            ->andReturn([
                'status_code' => 200,
                'body' => 'Not a valid JSON',
                'headers' => '',
            ])
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(InvalidResponse::class);
        $this->expectExceptionMessage('B2 API returned not valid JSON response');

        $client->request('POST', '/endpoint', $parameters);
    }

    public function testCreatePublicBucket(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_create_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketName' => 'Test bucket',
                'bucketType' =>  B2Client::BUCKET_TYPE_PUBLIC,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'create_bucket_public.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->createBucket([
            'BucketName' => 'Test bucket',
            'BucketType' => B2Client::BUCKET_TYPE_PUBLIC,
        ]);

        $this->assertEquals($this->getDecodedResponse('create_bucket_public.json'), $response);
    }

    public function testCreatePrivateBucket(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_create_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketName' => 'Test bucket',
                'bucketType' =>  B2Client::BUCKET_TYPE_PRIVATE,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'create_bucket_private.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->createBucket([
            'BucketName' => 'Test bucket',
            'BucketType' => B2Client::BUCKET_TYPE_PRIVATE,
        ]);

        $this->assertEquals($this->getDecodedResponse('create_bucket_private.json'), $response);
    }

    public function testCreateBucketUnauthorized(): void
    {
        $httpClient = $this->getHttpClientMock();

        $client = new B2Client($httpClient);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Please authorize before performing requests to Backblaze B2 API');

        $client->createBucket([
            'BucketName' => 'Test bucket',
            'BucketType' => B2Client::BUCKET_TYPE_PUBLIC
        ]);
    }

    public function testBucketAlreadyExistsExceptionThrown(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_create_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketName' => 'I already exist',
                'bucketType' =>  B2Client::BUCKET_TYPE_PRIVATE,
            ]))
            ->once()
            ->andReturn($this->makeResponse(400, 'create_bucket_exists.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(BucketAlreadyExistsException::class);
        $this->expectExceptionMessage('Received error from B2: Bucket name is already in use.');

        $client->createBucket([
            'BucketName' => 'I already exist',
            'BucketType' => B2Client::BUCKET_TYPE_PRIVATE,
        ]);
    }

    public function testInvalidBucketTypeThrowsException(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_create_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketName' => 'I already exist',
                'bucketType' =>  B2Client::BUCKET_TYPE_PRIVATE,
            ]))
            ->once()
            ->andReturn($this->makeResponse(400, 'create_bucket_exists.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(InvalidOptionsException::class);
        $this->expectExceptionMessage('Bucket type must be "allPublic" or "allPrivate"');

        $client->createBucket([
            'BucketName' => 'Test bucket',
            'BucketType' => 'i am not valid'
        ]);
    }

    public function testUpdateBucketToPrivate(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_update_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketId' => 'bucketId',
                'bucketType' => B2Client::BUCKET_TYPE_PRIVATE,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'update_bucket_to_private.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->updateBucket([
            'BucketId' => 'bucketId',
            'BucketType' => B2Client::BUCKET_TYPE_PRIVATE,
        ]);

        $this->assertEquals($this->getDecodedResponse('update_bucket_to_private.json'), $response);
    }

    public function testUpdateBucketToPublic(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_update_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketId' => 'bucketId',
                'bucketType' => B2Client::BUCKET_TYPE_PUBLIC,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'update_bucket_to_public.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->updateBucket([
            'BucketId' => 'bucketId',
            'BucketType' => B2Client::BUCKET_TYPE_PUBLIC,
        ]);

        $this->assertEquals($this->getDecodedResponse('update_bucket_to_public.json'), $response);
    }

    public function testListBuckets(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_list_buckets', [
                'accountId' => self::ACCOUNT_ID,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'list_buckets.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->listBuckets();

        $this->assertEquals($this->getDecodedResponse('list_buckets.json'), $response);
    }

    public function testDeleteBucket(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_delete_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketId' => 'bucketId'
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'delete_bucket.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->deleteBucket([
            'BucketId' => 'bucketId'
        ]);

        $this->assertEquals($this->getDecodedResponse('delete_bucket.json'), $response);
    }

    /**
     * @dataProvider getBucketIdByNameDataProvider
     */
    public function testGetBucketIdByName(string $bucketName, ?string $expectedBucketId): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_list_buckets', [
                'accountId' => self::ACCOUNT_ID,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'list_buckets.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $bucketId = $client->getBucketIdByName($bucketName);

        $this->assertEquals($expectedBucketId, $bucketId);
    }

    public function getBucketIdByNameDataProvider(): array
    {
        return [
            ['Puppy Videos', '5b232e8875c6214145260818'],
            ['My Bucket', null],
        ];
    }

    /**
     * @dataProvider getBucketNameByIdDataProvider
     */
    public function testGetBucketNameById(string $bucketId, ?string $expectedBucketName): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_list_buckets', [
                'accountId' => self::ACCOUNT_ID,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'list_buckets.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $bucketName = $client->getBucketNameById($bucketId);

        $this->assertEquals($expectedBucketName, $bucketName);
    }

    public function getBucketNameByIdDataProvider(): array
    {
        return [
            ['5b232e8875c6214145260818', 'Puppy Videos'],
            ['5b232e88a0bc214144837295', null],
        ];
    }

    public function testBadJsonThrownDeletingNonExistentBucket(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_delete_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketId' => 'bucketId'
            ]))
            ->once()
            ->andReturn($this->makeResponse(400, 'delete_bucket_non_existent.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(BadJsonException::class);
        $this->expectExceptionMessage('Received error from B2: bucketId not valid for account');

        $client->deleteBucket([
            'BucketId' => 'bucketId'
        ]);
    }

    public function testBucketNotEmptyThrownDeletingNonEmptyBucket(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_delete_bucket', [
                'accountId' => self::ACCOUNT_ID,
                'bucketId' => 'bucketId'
            ]))
            ->once()
            ->andReturn($this->makeResponse(400, 'bucket_not_empty.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(BucketNotEmptyException::class);
        $this->expectExceptionMessage('Cannot delete non-empty bucket');

        $client->deleteBucket([
            'BucketId' => 'bucketId'
        ]);
    }

    public function testUploadFileByPath(): void
    {
        $content = 'The quick brown box jumps over the lazy dog';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_get_upload_url', [
                'bucketId' => 'bucketId'
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'get_upload_url.json'))
        ;

        $httpClient
            ->shouldReceive('request')
            ->withArgs(function (string $method, string $url, string $body, array $headers) use ($content) {
                if ($method !== 'POST') {
                    return false;
                }

                if ($url !== 'uploadUrl') {
                    return false;
                }

                if ($body !== $content) {
                    return false;
                }

                $headersDiff = array_diff($headers, [
                    'Authorization' => 'authToken',
                    'Content-Type' => 'b2/x-auto',
                    'Content-Length' => strlen($content),
                    'X-Bz-File-Name' => 'test.txt',
                    'X-Bz-Content-Sha1' => sha1($content),
                ]);

                // we can't check "X-Bz-Info-src_last_modified_millis" because it uses microtime() function
                if (array_keys($headersDiff) !== ['X-Bz-Info-src_last_modified_millis']) {
                    return false;
                }

                // just check that last modified time is a float
                return is_float($headersDiff['X-Bz-Info-src_last_modified_millis']);
            })
            ->once()
            ->andReturn($this->makeResponse(200, 'upload.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $sourceFilePath = tempnam(sys_get_temp_dir(), 'B2_PHP_SDK_Test');

        file_put_contents($sourceFilePath, $content);

        $response = $client->upload([
            'BucketId' => 'bucketId',
            'FileName' => 'test.txt',
            'SourceFile' => $sourceFilePath,
        ]);

        $this->assertEquals($this->getDecodedResponse('upload.json'), $response);
    }

    public function testUploadResource(): void
    {
        $content = 'The quick brown box jumps over the lazy dog';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_get_upload_url', [
                'bucketId' => 'bucketId'
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'get_upload_url.json'))
        ;

        $httpClient
            ->shouldReceive('request')
            ->withArgs(function (string $method, string $url, string $body, array $headers) use ($content) {
                if ($method !== 'POST') {
                    return false;
                }

                if ($url !== 'uploadUrl') {
                    return false;
                }

                if ($body !== $content) {
                    return false;
                }

                $headersDiff = array_diff($headers, [
                    'Authorization' => 'authToken',
                    'Content-Type' => 'b2/x-auto',
                    'Content-Length' => strlen($content),
                    'X-Bz-File-Name' => 'test.txt',
                    'X-Bz-Content-Sha1' => sha1($content),
                ]);

                // we can't check "X-Bz-Info-src_last_modified_millis" because it uses microtime() function
                if (array_keys($headersDiff) !== ['X-Bz-Info-src_last_modified_millis']) {
                    return false;
                }

                // just check that last modified time is a float
                return is_float($headersDiff['X-Bz-Info-src_last_modified_millis']);
            })
            ->once()
            ->andReturn($this->makeResponse(200, 'upload.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $resource = fopen('php://memory', 'r+');

        fwrite($resource, $content);
        rewind($resource);

        $response = $client->upload([
            'BucketId' => 'bucketId',
            'FileName' => 'test.txt',
            'Body' => $resource,
        ]);

        $this->assertEquals($this->getDecodedResponse('upload.json'), $response);
    }

    public function testUploadString(): void
    {
        $content = 'The quick brown box jumps over the lazy dog';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_get_upload_url', [
                'bucketId' => 'bucketId'
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'get_upload_url.json'))
        ;

        $httpClient
            ->shouldReceive('request')
            ->withArgs(function (string $method, string $url, string $body, array $headers) use ($content) {
                if ($method !== 'POST') {
                    return false;
                }

                if ($url !== 'uploadUrl') {
                    return false;
                }

                if ($body !== $content) {
                    return false;
                }

                $headersDiff = array_diff($headers, [
                    'Authorization' => 'authToken',
                    'Content-Type' => 'b2/x-auto',
                    'Content-Length' => strlen($content),
                    'X-Bz-File-Name' => 'test.txt',
                    'X-Bz-Content-Sha1' => sha1($content),
                ]);

                // we can't check "X-Bz-Info-src_last_modified_millis" because it uses microtime() function
                if (array_keys($headersDiff) !== ['X-Bz-Info-src_last_modified_millis']) {
                    return false;
                }

                // just check that last modified time is a float
                return is_float($headersDiff['X-Bz-Info-src_last_modified_millis']);
            })
            ->once()
            ->andReturn($this->makeResponse(200, 'upload.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->upload([
            'BucketId' => 'bucketId',
            'FileName' => 'test.txt',
            'Body' => $content,
        ]);

        $this->assertEquals($this->getDecodedResponse('upload.json'), $response);
    }

    public function testUploadWithCustomContentTypeAndLastModified(): void
    {
        $content = 'The quick brown box jumps over the lazy dog';
        $lastModified = 1558184879;
        $contentType = 'text/plain';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_get_upload_url', [
                'bucketId' => 'bucketId'
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'get_upload_url.json'))
        ;

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'POST',
                'uploadUrl',
                $content,
                [
                    'Authorization' => 'authToken',
                    'Content-Type' => $contentType,
                    'Content-Length' => strlen($content),
                    'X-Bz-File-Name' => 'test.txt',
                    'X-Bz-Content-Sha1' => sha1($content),
                    'X-Bz-Info-src_last_modified_millis' => $lastModified,
                ],
            ])
            ->once()
            ->andReturn($this->makeResponse(200, 'upload.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->upload([
            'BucketId' => 'bucketId',
            'FileName' => 'test.txt',
            'Body' => $content,
            'FileContentType' => $contentType,
            'FileLastModified' => $lastModified,
        ]);

        $this->assertEquals($this->getDecodedResponse('upload.json'), $response);
    }

    public function testDownload(): void
    {
        $fileId = '4_z4c2b953461da9c825f260e1b_f1114dbf5bg9707e8_d20160206_m012226_c001_v1111017_t0010';

        $httpClient = $this->getHttpClientMock();

        $expectedQueryString = http_build_query([
            'fileId' => $fileId,
        ]);

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                'https://f900.backblaze.com/b2api/v1/b2_download_file_by_id?' . $expectedQueryString,
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(200, 'download_content'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $saveFilePath = tempnam(sys_get_temp_dir(), 'B2_PHP_SDK_Test');

        $client->download([
            'FileId' => $fileId,
            'SaveAs' => $saveFilePath,
        ]);

        $this->assertEquals($this->getStub('download_content'), file_get_contents($saveFilePath));
    }

    public function testDownloadWithIncorrectFileId(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                'https://f900.backblaze.com/b2api/v1/b2_download_file_by_id?fileId=incorrect',
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(400, 'download_by_incorrect_id.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(BadValueException::class);
        $this->expectExceptionMessage('Received error from B2: bad fileId: incorrect');

        $client->download([
            'FileId' => 'incorrect',
            'SaveAs' => '/path/to/save',
        ]);
    }

    public function testDownloadByName(): void
    {
        $bucketName = 'my-bucket';
        $fileName = 'path/to/file';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                "https://f900.backblaze.com/file/{$bucketName}/{$fileName}",
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(200, 'download_content'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $saveFilePath = tempnam(sys_get_temp_dir(), 'B2_PHP_SDK_Test');

        $client->downloadByName([
            'BucketName' => $bucketName,
            'FileName' => $fileName,
            'SaveAs' => $saveFilePath,
        ]);

        $this->assertEquals($this->getStub('download_content'), file_get_contents($saveFilePath));
    }

    public function testDownloadByNameByNameWithIncorrectPath(): void
    {
        $bucketName = 'my-bucket';
        $fileName = 'path/to/file';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                "https://f900.backblaze.com/file/{$bucketName}/{$fileName}",
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(400, 'download_by_incorrect_path.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Received error from B2: bucket my-bucket does not have file: path/to/file');

        $client->downloadByName([
            'BucketName' => $bucketName,
            'FileName' => $fileName,
            'SaveAs' => '/path/to/save',
        ]);
    }

    public function testGetFileContent(): void
    {
        $fileId = '4_z4c2b953461da9c825f260e1b_f1114dbf5bg9707e8_d20160206_m012226_c001_v1111017_t0010';

        $httpClient = $this->getHttpClientMock();

        $expectedQueryString = http_build_query([
            'fileId' => $fileId,
        ]);

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                'https://f900.backblaze.com/b2api/v1/b2_download_file_by_id?' . $expectedQueryString,
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(200, 'download_content'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->getFileContent([
            'FileId' => $fileId,
        ]);

        $this->assertEquals($this->getStub('download_content'), $response);
    }

    public function testGetFileContentWithIncorrectFileId(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                'https://f900.backblaze.com/b2api/v1/b2_download_file_by_id?fileId=incorrect',
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(400, 'download_by_incorrect_id.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(BadValueException::class);
        $this->expectExceptionMessage('Received error from B2: bad fileId: incorrect');

        $client->getFileContent([
            'FileId' => 'incorrect',
        ]);
    }

    public function testGetFileContentByName(): void
    {
        $bucketName = 'my-bucket';
        $fileName = 'path/to/file';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                "https://f900.backblaze.com/file/{$bucketName}/{$fileName}",
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(200, 'download_content'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->getFileContentByName([
            'BucketName' => $bucketName,
            'FileName' => $fileName,
        ]);

        $this->assertEquals($this->getStub('download_content'), $response);
    }

    public function testGetFileContentByNameWithIncorrectPath(): void
    {
        $bucketName = 'my-bucket';
        $fileName = 'path/to/file';

        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                "https://f900.backblaze.com/file/{$bucketName}/{$fileName}",
                '',
                ['Authorization' => 'testAuthToken'],
            ])
            ->once()
            ->andReturn($this->makeResponse(400, 'download_by_incorrect_path.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Received error from B2: bucket my-bucket does not have file: path/to/file');

        $client->getFileContentByName([
            'BucketName' => $bucketName,
            'FileName' => $fileName,
        ]);
    }

    public function testListFiles(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_list_file_names', [
                'bucketId' => 'bucketId',
                'startFileName' => null,
                'maxFileCount' => 1000,
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'list_files.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->listFiles([
            'BucketId' => 'bucketId',
        ]);

        $this->assertEquals($this->getDecodedResponse('list_files.json'), $response);
    }

    /**
     * @dataProvider fileExistsDataProvider
     */
    public function testFileExists(string $fileName, array $response, bool $expectedResult): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_list_file_names', [
                'bucketId' => 'bucketId',
                'startFileName' => $fileName,
                'maxFileCount' => 1,
            ]))
            ->once()
            ->andReturn([
                'status_code' => 200,
                'body' => json_encode($response),
                'headers' => [],
            ])
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $result = $client->fileExists([
            'BucketId' => 'bucketId',
            'FileName' => $fileName,
        ]);

        $this->assertEquals($expectedResult, $result);
    }

    public function fileExistsDataProvider(): array
    {
        return [
            'existing file' => [
                'my-file',
                [
                    'action' => 'upload',
                    'fileId' => '4_z4c2b957661hy9c825f260e1b_f115af4dca081b246_d20160131_m160446_f001_v0011017_t0002',
                    'fileName' => 'my-file',
                    'size' => 140827,
                    'uploadTimestamp' => 1454256286000
                ],
                true,
            ],
            'non existing file' => [
                'non-existing-file',
                [],
                false,
            ]
        ];
    }

    public function testGetFileInfo(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_get_file_info', [
                'fileId' => 'fileId',
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'get_file.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->getFileInfo([
            'FileId' => 'fileId',
        ]);

        $this->assertEquals($this->getDecodedResponse('get_file.json'), $response);
    }

    public function testGetFileInfoWithNonExistentFileThrowsException(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_get_file_info', [
                'fileId' => 'fileId',
            ]))
            ->once()
            ->andReturn($this->makeResponse(400, 'get_file_non_existent.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(BadJsonException::class);
        $this->expectExceptionMessage('Received error from B2: Bad fileId: fileId');

        $client->getFileInfo([
            'FileId' => 'fileId',
        ]);
    }

    public function testDeleteFile(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_delete_file_version', [
                'fileName' => 'fileName',
                'fileId' => 'fileId',
            ]))
            ->once()
            ->andReturn($this->makeResponse(200, 'delete_file.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $response = $client->deleteFile([
            'FileName' => 'fileName',
            'FileId' => 'fileId',
        ]);

        $this->assertEquals($this->getDecodedResponse('delete_file.json'), $response);
    }

    public function testDeletingNonExistentFileThrowsException(): void
    {
        $httpClient = $this->getHttpClientMock();

        $httpClient
            ->shouldReceive('request')
            ->withArgs($this->getExpectedArgsForRequestMethod('/b2_delete_file_version', [
                'fileName' => 'fileName',
                'fileId' => 'file_id',
            ]))
            ->once()
            ->andReturn($this->makeResponse(400, 'delete_file_non_existent_file.json'))
        ;

        $client = new B2Client($httpClient);

        $client->authorize(self::ACCOUNT_ID, self::APPLICATION_ID);

        $this->expectException(BadJsonException::class);
        $this->expectExceptionMessage('Received error from B2: Bad fileId: file_id');

        $client->deleteFile([
            'FileName' => 'fileName',
            'FileId' => 'file_id',
        ]);
    }

    private function getHttpClientMock()
    {
        $httpClient = Mockery::mock(HttpClientInterface::class);

        $credentials = base64_encode(self::ACCOUNT_ID . ':' . self::APPLICATION_ID);

        $headers = [
            'Authorization' => 'Basic ' . $credentials,
        ];

        $httpClient
            ->shouldReceive('request')
            ->withArgs([
                'GET',
                'https://api.backblazeb2.com/b2api/v1/b2_authorize_account',
                '',
                $headers,
            ])
            ->once()
            ->andReturn($this->makeResponse(200, 'authorize_account.json'))
        ;

        return $httpClient;
    }

    private function makeResponse(int $statusCode, string $responseFile): array
    {
        return [
            'status_code' => $statusCode,
            'headers' => [],
            'body' => $this->getStub($responseFile),
        ];
    }

    private function getStub(string $responseFile): string
    {
        return file_get_contents(__DIR__ . '/responses/' . $responseFile);
    }

    private function getDecodedResponse(string $responseFile): array
    {
        return json_decode($this->getStub($responseFile), true);
    }

    private function getExpectedArgsForRequestMethod(string $endpoint, array $parameters): array
    {
        $headers = [
            'Authorization' => 'testAuthToken',
        ];

        return [
            'POST',
            $this->getEndpointUrl($endpoint),
            json_encode($parameters),
            $headers,
        ];
    }

    private function getEndpointUrl(string $endpoint): string
    {
        return 'https://api900.backblaze.com/b2api/v1' . $endpoint;
    }
}
