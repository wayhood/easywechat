<?php

declare(strict_types=1);

namespace EasyWeChat\Aspect;

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;


class ServerRequestCreatorAspect extends AbstractAspect
{
    public array $classes = [
        'Nyholm\Psr7Server\ServerRequestCreator::fromGlobals',
    ];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        return $this->fromGlobals($proceedingJoinPoint);
    }

    public function fromArrays(ProceedingJoinPoint $proceedingJoinPoint, array $server, array $headers = [], array $cookie = [], array $get = [], ?array $post = null, array $files = [], $body = null): ServerRequestInterface
    {
        $method = $this->getMethodFromEnv($server);
        $uri = $this->getUriFromEnvWithHTTP($proceedingJoinPoint, $server);
        $protocol = isset($server['server_protocol']) ? \str_replace('HTTP/', '', $server['server_protocol']) : '1.1';
        $instance = $proceedingJoinPoint->getInstance();

        $reflectionClass = new \ReflectionClass($instance);
        $serverRequestFactoryReflectionProperty = $reflectionClass->getProperty('serverRequestFactory');
        $serverRequestFactoryReflectionProperty->setAccessible(true);
        $serverRequestFactory = $serverRequestFactoryReflectionProperty->getValue($instance);
        $serverRequestFactoryReflectionClass = new \ReflectionClass($serverRequestFactory);
        $createServerRequestReflectionMethod = $serverRequestFactoryReflectionClass->getMethod('createServerRequest');
        $serverRequest = $createServerRequestReflectionMethod->invoke($serverRequestFactory, $method, $uri, $server);

        foreach ($headers as $name => $value) {
            // Because PHP automatically casts array keys set with numeric strings to integers, we have to make sure
            // that numeric headers will not be sent along as integers, as withAddedHeader can only accept strings.
            if (\is_int($name)) {
                $name = (string) $name;
            }
            $serverRequest = $serverRequest->withAddedHeader($name, $value);
        }

        //        $reflectionClass = new \ReflectionClass($instance);
        //        $reflectionProperty = $reflectionClass->getProperty('serverRequestFactory');
        //        $reflectionProperty->setAccessible(true);
        //        $f  = $instance->in
        //        $files = $this->normalizeFiles($files);
        $serverRequest = $serverRequest
            ->withProtocolVersion($protocol)
            ->withCookieParams($cookie)
            ->withQueryParams($get)
            ->withParsedBody($post);
        //            ->withUploadedFiles();

        if ($body === null) {
            return $serverRequest;
        }

        $streamFactoryReflectionProperty = $reflectionClass->getProperty('streamFactory');
        $streamFactoryReflectionProperty->setAccessible(true);
        $streamFactory = $streamFactoryReflectionProperty->getValue($instance);
        $streamFactoryReflectionClass = new \ReflectionClass($streamFactory);

        if (\is_resource($body)) {
            $createStreamFromResourceMethod = $streamFactoryReflectionClass->getMethod('createStreamFromResource');
            $body = $createStreamFromResourceMethod->invoke($streamFactory, $body);
        } elseif (\is_string($body)) {
            $createStreamMethod = $streamFactoryReflectionClass->getMethod('createStream');
            $body = $createStreamMethod->invoke($streamFactory, $body);
        } elseif (! $body instanceof StreamInterface) {
            throw new \InvalidArgumentException('The $body parameter to ServerRequestCreator::fromArrays must be string, resource or StreamInterface');
        }

        return $serverRequest->withBody($body);
    }

    private function getUriFromEnvWithHTTP(ProceedingJoinPoint $proceedingJoinPoint, array $environment): UriInterface
    {
        $instance = $proceedingJoinPoint->getInstance();
        $reflectionClass = new \ReflectionClass($instance);

        $uriFactoryReflectionProperty = $reflectionClass->getProperty('uriFactory');
        $uriFactoryReflectionProperty->setAccessible(true);
        $uriFactory = $uriFactoryReflectionProperty->getValue($instance);
        $uriFactoryReflectionClass = new \ReflectionClass($uriFactory);
        $createUriMethod = $uriFactoryReflectionClass->getMethod('createUri');
        $uri = $createUriMethod->invoke($uriFactory, '');

        if (isset($server['https'])) {
            $uri = $uri->withScheme($server['https'] === 'on' ? 'https' : 'http');
        }

        if (isset($server['server_port'])) {
            $uri = $uri->withPort($server['server_port']);
        }

        $uri->withHost('0.0.0.0');

        if (isset($server['request_uri'])) {
            $uri = $uri->withPath(\current(\explode('?', $server['request_uri'])));
        }

        if (isset($server['query_string'])) {
            $uri = $uri->withQuery($server['query_string']);
        }

        return $uri;
    }

    private function fromGlobals(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $request = ApplicationContext::getContainer()->get(RequestInterface::class);
        $get = $request->getQueryParams();
        $post = $request->getParsedBody();
        $cookie = $request->getCookieParams();
        $uploadFiles = $request->getUploadedFiles() ?? [];
        $server = $request->getServerParams();
        $body = $request->getBody()->getContents();
        $headers = $request->getHeaders();

        if (isset($server['request_method']) === false) {
            $server['request_method'] = 'GET';
        }

        $post = null;

        if ($this->getMethodFromEnv($server) === 'POST') {
            foreach ($headers as $headerName => $headerValue) {
                if (\is_int($headerName) === true || \strtolower($headerName) !== 'content-type') {
                    continue;
                }
                if (\in_array(
                    \strtolower(\trim(\explode(';', $headerValue, 2)[0])),
                    ['application/x-www-form-urlencoded', 'multipart/form-data']
                )) {
                    $post = $request->getParsedBody();
                    break;
                }
            }
        }
        return $this->fromArrays($proceedingJoinPoint, $server, $headers, $cookie, $get, $post, $uploadFiles, $body ?: null);
    }

    private function getMethodFromEnv(array $environment): string
    {
        if (isset($environment['request_method']) === false) {
            throw new \InvalidArgumentException('Cannot determine HTTP method');
        }

        return $environment['request_method'];
    }

//    private function normalizeFiles(array $files): array
//    {
//        $normalized = [];
//
//        foreach ($files as $key => $value) {
//            if ($value instanceof UploadedFileInterface) {
//                $normalized[$key] = $value;
//            } elseif (\is_array($value) && isset($value['tmp_name'])) {
//                $normalized[$key] = $this->createUploadedFileFromSpec($value);
//            } elseif (\is_array($value)) {
//                $normalized[$key] = $this->normalizeFiles($value);
//            } else {
//                throw new \InvalidArgumentException('Invalid value in files specification');
//            }
//        }
//    }
}
