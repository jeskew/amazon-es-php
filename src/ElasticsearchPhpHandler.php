<?php
namespace Aws\ElasticsearchService;

use Aws\Credentials\CredentialProvider;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class ElasticsearchPhpHandler
{
    private $signer;
    private $credentialProvider;
    private $wrappedHandler;

    /**
     * An AWS Signature V4 signing handler for use with Elasticsearch-PHP or
     * Opensearch-PHP and Amazon Elasticsearch Service.
     *
     * @param string        $region                 The region of your Amazon
     *                                              Elasticsearch Service domain
     * @param callable|null $credentialProvider     A callable that returns a
     *                                              promise that is fulfilled
     *                                              with an instance of
     *                                              Aws\Credentials\Credentials
     * @param callable|null $wrappedHandler         A RingPHP handler
     */
    public function __construct(
        $region,
        callable $credentialProvider = null,
        callable $wrappedHandler = null
    ) {
        $this->signer = new SignatureV4('es', $region);
        $this->wrappedHandler = $wrappedHandler
            ?: static::defaultHandler();
        $this->credentialProvider = $credentialProvider
            ?: CredentialProvider::defaultProvider();
    }

    public function __invoke(array $request)
    {
        $creds = call_user_func($this->credentialProvider)->wait();
        $psr7Request = $this->createPsr7Request($request);
        $signedRequest = $this->signer
            ->signRequest($psr7Request, $creds);

        return call_user_func($this->wrappedHandler, $this->createRingRequest($signedRequest));
    }

    private function createPsr7Request(array $ringPhpRequest)
    {
        // fix for uppercase 'Host' array key in elasticsearch-php 5.3.1 and backward compatible
        // https://github.com/aws/aws-sdk-php/issues/1225
        $hostKey = isset($ringPhpRequest['headers']['Host'])? 'Host' : 'host';

        // Amazon ES/OS listens on standard ports (443 for HTTPS, 80 for HTTP).
        // Consequently, the port should be stripped from the host header.
        $parsedUrl = parse_url($ringPhpRequest['headers'][$hostKey][0]);
        if (isset($parsedUrl['host'])) {
            $ringPhpRequest['headers'][$hostKey][0] = $parsedUrl['host'];
        }

        // Create a PSR-7 URI from the array passed to the handler
        $uri = (new Uri($ringPhpRequest['uri']))
            ->withScheme($ringPhpRequest['scheme'])
            ->withHost($ringPhpRequest['headers'][$hostKey][0]);
        if (isset($ringPhpRequest['query_string'])) {
            $uri = $uri->withQuery($ringPhpRequest['query_string']);
        }

        // Create a PSR-7 request from the array passed to the handler
        return new Request(
            $ringPhpRequest['http_method'],
            $uri,
            $ringPhpRequest['headers'],
            $ringPhpRequest['body']
        );
    }

    private function createRingRequest(RequestInterface $request)
    {
        $uri = $request->getUri();
        $body = (string) $request->getBody();

        // RingPHP currently expects empty message bodies to be null:
        // https://github.com/guzzle/RingPHP/blob/4c8fe4c48a0fb7cc5e41ef529e43fecd6da4d539/src/Client/CurlFactory.php#L202
        if (empty($body)) {
            $body = null;
        }

        $ringRequest = [
            'http_method' => $request->getMethod(),
            'scheme' => $uri->getScheme(),
            'uri' => $uri->getPath(),
            'body' => $body,
            'headers' => $request->getHeaders(),
        ];
        if ($uri->getQuery()) {
            $ringRequest['query_string'] = $uri->getQuery();
        }

        return $ringRequest;
    }

    /**
     * Get the default handler closure from either the opensearch-php or the elasticsearch-php library.
     *
     * @throws RuntimeException
     */
    public static function defaultHandler(array $multiParams = [], array $singleParams = [])
    {
        $builderClass = static::clientBuilderClass();

        return $builderClass::defaultHandler($multiParams, $singleParams);
    }

    /**
     * Determine the client builder class on the installed search library
     */
    protected static function clientBuilderClass()
    {
        // Opensearch-PHP
        if (class_exists('\OpenSearch\ClientBuilder')) {
            return '\OpenSearch\ClientBuilder';
        }

        // Elasticsearch-PHP < 8.0
        if (class_exists('\Elasticsearch\ClientBuilder')) {
            return '\Elasticsearch\ClientBuilder';
        }

        // Elasticsearch-PHP >= 8.0
        if (class_exists('\Elastic\Elasticsearch\ClientBuilder')) {
            throw new RuntimeException('Elasticsearch-PHP is incompatible from version 8.');
        }

        throw new RuntimeException('ElasticsearchPhpHandler requires either Elasticsearch-PHP or Opensearch-PHP.');
    }
}
