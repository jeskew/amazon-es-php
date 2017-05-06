# AWS Auth Elasticsearch-PHP Handler

[![Apache 2 License](https://img.shields.io/packagist/l/jsq/amazon-es-php.svg?style=flat)](https://www.apache.org/licenses/LICENSE-2.0.html)
[![Total Downloads](https://img.shields.io/packagist/dt/jsq/amazon-es-php.svg?style=flat)](https://packagist.org/packages/jsq/amazon-es-php)
[![Author](http://img.shields.io/badge/author-@jreskew-blue.svg?style=flat-square)](https://twitter.com/jreskew)
[![Build Status](https://travis-ci.org/jeskew/amazon-es-php.svg?branch=master)](https://travis-ci.org/jeskew/amazon-es-php)

This package provides a signing handler for use with the official
Elasticsearch-PHP (`elasticsearch/elasticsearch`) client. By default, the
handler will load AWS credentials from the environment and send requests using a
RingPHP cURL handler.

## Basic Usage

Instances of `Aws\ElasticsearchService\ElasticsearchPhpHandler` are callables
that fulfill Elasticsearch-PHP's handler contract. They can be passed to
`Elasticsearch\ClientBuilder`'s `setHandler` method:
```php
use Aws\ElasticsearchService\ElasticsearchPhpHandler;
use Elasticsearch\ClientBuilder;

// Create a handler (with the region of your Amazon Elasticsearch Service domain)
$handler = new ElasticsearchPhpHandler('us-west-2');

// Use this handler to create an Elasticsearch-PHP client
$client = ClientBuilder::create()
    ->setHandler($handler)
    ->setHosts(['https://search-foo-3gn4utxfus5cqpn89go4z5lbsm.us-west-2.es.amazonaws.com:443'])
    ->build();

// Use the client as you normally would
$client->index([
    'index' => $index,
    'type' => $type,
    'id' => $id,
    'body' => [$key => $value]
]);
```

## Using custom credentials

By default, the handler will attempt to source credentials from the environment
as described [in the AWS SDK for PHP documentation](http://docs.aws.amazon.com/aws-sdk-php/v3/guide/guide/credentials.html).
To use custom credentials, pass in a [credential provider](http://docs.aws.amazon.com/aws-sdk-php/v3/guide/guide/credentials.html#credential-provider):
```php
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\ElasticsearchService\ElasticsearchPhpHandler;

$provider = CredentialProvider::fromCredentials(
    new Credentials('foo', 'bar', 'baz')
);

$handler = new ElasticsearchPhpHandler('us-west-2', $provider);
```

## Using a custom HTTP handler

By default, the handler will use `Elasticsearch\ClientBuilder::defaultHandler()`
to dispatch HTTP requests, but this is customizable via an optional constructor
parameter. For example, this repository's tests use a custom handler to mock
network traffic:
```php
class ElasticsearchPhpHandlerTest extends \PHPUnit_Framework_TestCase
{
    public function testSignsRequestsPassedToHandler()
    {
        $toWrap = function (array $ringRequest) {
            $this->assertArrayHasKey('X-Amz-Date', $ringRequest['headers']);
            $this->assertArrayHasKey('Authorization', $ringRequest['headers']);
            $this->assertStringStartsWith(
                'AWS4-HMAC-SHA256 Credential=',
                $ringRequest['headers']['Authorization'][0]
            );

            return $this->getGenericResponse();
        };
        $handler = new ElasticsearchPhpHandler('us-west-2', null, $toWrap);

        $client = \Elasticsearch\ClientBuilder::create()
            ->setHandler($handler)
            ->build();

        $client->get([
            'index' => 'index',
            'type' => 'type',
            'id' => 'id',
        ]);
    }
    ...
}
```

## Installation

### Composer

```
composer require jsq/amazon-es-php
```
