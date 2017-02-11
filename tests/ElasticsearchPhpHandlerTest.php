<?php
namespace Aws\ElasticsearchService;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use GuzzleHttp\Ring\Future\CompletedFutureArray;

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
        $provider = CredentialProvider::fromCredentials(
            new Credentials('foo', 'bar', 'baz')
        );
        $handler = new ElasticsearchPhpHandler('us-west-2', $provider, $toWrap);
        
        $client = \Elasticsearch\ClientBuilder::create()
            ->setHandler($handler)
            ->build();
        
        $client->get([
            'index' => 'index',
            'type' => 'type',
            'id' => 'id',
        ]);
    }
    
    public function testSignsWithProvidedCredentials()
    {
        $provider = CredentialProvider::fromCredentials(
            new Credentials('foo', 'bar', 'baz')
        );
        $toWrap = function (array $ringRequest) {
            $this->assertArrayHasKey('X-Amz-Security-Token', $ringRequest['headers']);
            $this->assertSame('baz', $ringRequest['headers']['X-Amz-Security-Token'][0]);
            $this->assertRegExp(
                '~^AWS4-HMAC-SHA256 Credential=foo/\d{8}/us-west-2/es/aws4_request~', 
                $ringRequest['headers']['Authorization'][0]
            );

            return $this->getGenericResponse();
        };
        
        $handler = new ElasticsearchPhpHandler('us-west-2', $provider, $toWrap);

        $client = \Elasticsearch\ClientBuilder::create()
            ->setHandler($handler)
            ->build();
        
        $client->get([
            'index' => 'index',
            'type' => 'type',
            'id' => 'id',
        ]);
    }
    
    private function getGenericResponse()
    {
        return new CompletedFutureArray([
            'status' => 200,
            'body' => fopen('php://memory', 'r'),
            'transfer_stats' => ['total_time' => 0],
            'effective_url' => 'https://www.example.com',
        ]);
    }
}
