<?php
namespace Aws\ElasticsearchService;

use Aws\Credentials\CredentialProvider;
use Aws\Signature\SignatureV4;
use Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;

class ElasticsearchPhpHandler
{
    private $signer;
    private $credentialProvider;
    private $wrappedHandler;

    /**
     * An AWS Signature V4 signing handler for use with Elasticsearch-PHP and
     * Amazon Elasticsearch Service.
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
            ?: ClientBuilder::defaultHandler();
        $this->credentialProvider = $credentialProvider
            ?: CredentialProvider::defaultProvider();
    }

    public function __invoke(array $request)
    {
        $creds = call_user_func($this->credentialProvider)->wait();
        $psr7Request = $this->createPsr7Request($request);
        $signedRequest = $this->signer
            ->signRequest($psr7Request, $creds);

        return call_user_func($this->wrappedHandler, array_replace(
            $request,
            $this->createRingRequest($signedRequest)
        ));
    }

    private function createPsr7Request(array $ringPhpRequest)
    {
        // fix for uppercase 'Host' array key in elasticsearch-php 5.3.1 and backward compatible
        // https://github.com/aws/aws-sdk-php/issues/1225
        $hostKey = isset($ringPhpRequest['headers']['Host'])? 'Host' : 'host';

        // Amazon ES listens on standard ports (443 for HTTPS, 80 for HTTP).
        // Consequently, the port should be stripped from the host header.
        $ringPhpRequest['headers'][$hostKey][0]
            = parse_url($ringPhpRequest['headers'][$hostKey][0])['host'];

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
        $ringRequest = [
            'http_method' => $request->getMethod(),
            'scheme' => $uri->getScheme(),
            'uri' => $uri->getPath(),
            'body' => (string) $request->getBody(),
            'headers' => $request->getHeaders(),
        ];
        if ($uri->getQuery()) {
            $ringRequest['query_string'] = $uri->getQuery();
        }

        return $ringRequest;
    }
}
