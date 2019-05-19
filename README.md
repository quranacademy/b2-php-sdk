## Backblaze B2 SDK for PHP

[![Build Status](https://img.shields.io/travis/quranacademy/b2-php-sdk.svg?style=flat-square)](https://travis-ci.org/quranacademy/b2-php-sdk)
[![Total Downloads](https://img.shields.io/packagist/dt/quranacademy/b2-php-sdk.svg?style=flat-square)](https://packagist.org/packages/quranacademy/b2-php-sdk)
[![Latest Version](https://img.shields.io/github/release/quranacademy/b2-php-sdk.svg?style=flat-square)](https://github.com/quranacademy/b2-php-sdk/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

`b2-php-sdk` is a client library for working with Backblaze B2 storage service. It aims to make using the service as
easy as possible by exposing a clear API and taking influence from other SDKs that you may be familiar with.

**[Backblaze B2 documentation](https://www.backblaze.com/b2/docs/)**

## Installation

To install Backblaze B2 SDK run the command:

```bash
$ composer require quranacademy/b2-php-sdk
```

## Usage

Most methods of the SDK returns the body of the response from B2 API.
Check out [Backblaze B2 documentation](https://www.backblaze.com/b2/docs/) to see what fields will be returned.

```php
use Backblaze\B2Client;
use Backblaze\HttpClient\CurlHttpClient;

$httpClient = new CurlHttpClient();

$client = new B2Client($httpClient);

$client->authorize('accountId', 'applicationKey');

// see "b2_create_bucket" operation in the docs
$bucket = $client->createBucket([
    'BucketName' => 'my-bucket',
    'BucketType' => B2Client::BUCKET_TYPE_PRIVATE, // or BUCKET_TYPE_PUBLIC
]);

// change the bucket to public
// see "b2_update_bucket"
$updatedBucket = $client->updateBucket([
    'BucketId' => $bucket['bucketId'],
    'BucketType' => B2Client::BUCKET_TYPE_PUBLIC,
]);

// retrieve a list of buckets on your account
// see "b2_list_buckets"
$buckets = $client->listBuckets();

// delete a bucket
// see "b2_delete_bucket"
$client->deleteBucket([
    'BucketId' => $bucket['bucketId'],
]);

// retrieve an array of file objects from a bucket
// see "b2_list_file_names"
$fileList = $client->listFiles([
    'BucketId' => '4a48fe8875c6214145260818',
]);

$fileExistence = $client->fileExists([
    'BucketId' => '4a48fe8875c6214145260818',
    'file' => 'path/to/file',
]);

// upload a file to a bucket
// see "b2_upload_file"
$file = $client->upload([
    'BucketId' => '4a48fe8875c6214145260818',
    'FileName' => 'path/to/upload/to',
    'Body' => 'File content',

    // the file content can also be provided via a resource
    // 'Body' => fopen('/path/to/source/file', 'r'),

    // or as the path to a source file
    // 'SourceFile' => '/path/to/source/file',
]);

$fileId = '4_z942111fa0b943d89249a0815_f1001e9fa2c42d9a8_d20170119_m162445_c001_v0001032_t0057';

// download a file from a bucket
$client->download([
    'FileId' => $fileId,
    'SaveAs' => '/path/to/save/location',
]);

// delete a file from a bucket
// see "b2_delete_file_version"
$fileDelete = $client->deleteFile([
    'FileId' => $fileId,
    'FileName' => '/path/to/file',
]);
```

## Tests

Tests are run with PHPUnit. After installing PHPUnit via Composer:

```bash
$ vendor/bin/phpunit
```

## Contributors

Feel free to contribute in any way you can whether that be reporting issues, making suggestions or sending PRs.
