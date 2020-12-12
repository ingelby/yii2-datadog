<?php

namespace Ingelby\Datadog\Logging;


use common\helpers\SessionGuid;
use Ingelby\Datadog\Logging\Exceptions\DataDogLogConfigurationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use yii\helpers\Json;
use yii\log\Logger;
use yii\log\Target;

class DataDogTarget extends Target
{
    public const MODE_HTTP = 'HTTP';
    //@Todo
    public const MODE_TCP = 'TCP';

    protected const DATA_DOG_HTTP_SEND_LOG_BASE_URL = 'https://http-intake.logs.datadoghq.eu';
    protected const DATA_DOG_SEND_LOG_URI = '/v1/input';

    public ?string $dataDogApiKey = null;
    public ?string $hostname = null;
    public ?string $environment = null;
    public ?string $service = null;

    public int $dataDogDefaultHttpTimeout = 1;
    public string $dataDogSource = 'yii2';
    public array $dataDogTags = [];

    public ?string $mode = self::MODE_HTTP;


    protected ?Client $guzzleClient = null;
    protected ?\DDTrace\Span $span = null;

    /**
     * {@inheritdoc}
     * @throws DataDogLogConfigurationException
     */
    public function init()
    {
        parent::init();

        if (null === $this->dataDogApiKey) {
            throw new DataDogLogConfigurationException('No DataDog API key');
        }
        if (null === $this->hostname) {
            throw new DataDogLogConfigurationException('No DataDog hostname');
        }
        if (null === $this->environment) {
            throw new DataDogLogConfigurationException('No DataDog environment');
        }
        if (null === $this->service) {
            throw new DataDogLogConfigurationException('No DataDog service');
        }

        $this->dataDogTags['env'] = $this->environment;

        $this->span = \DDTrace\GlobalTracer::get()->getActiveSpan();

        if (null === $this->guzzleClient) {
            $this->guzzleClient = new Client(
                [
                    'base_uri' => static::DATA_DOG_HTTP_SEND_LOG_BASE_URL,
                    'timeout'  => $this->dataDogDefaultHttpTimeout,
                ]
            );
        }
    }

    /**
     * @return string
     */
    protected function getDataDogTags(): string
    {
        $tagCollection = [];
        foreach ($this->dataDogTags as $key => $value) {
            $tagCollection[] = $key . ':' . $value;
        }

        return implode(',', $tagCollection);
    }

    /**
     * @inheritDoc
     */
    public function export()
    {
        $dataDogPayload = [];

        foreach ($this->messages as $message) {
            $dataDogPayload[] = [
                'ddsource' => $this->dataDogSource,
                'ddtags'   => $this->getDataDogTags(),
                'hostname' => $this->hostname,
                'message'  => $this->arrayFormatMessage($message),
                'service'  => $this->service,
            ];
        }

        $promise = $this->guzzleClient->postAsync(
            static::DATA_DOG_SEND_LOG_URI,
            [
                'headers' =>
                    [
                        'content-type' => 'application/json',
                        'dd-api-key'   => $this->dataDogApiKey,
                    ],
                'body'    => Json::encode($dataDogPayload),
            ]
        )->then(
            function (ResponseInterface $res) {
                return Json::decode($res->getBody()->getContents());
            },
            function (RequestException $e) {
                $response = [];
                $response['data'] = $e->getMessage();

                return $response;
            }
        );

        $promise->wait();
    }

    /**
     * @param $message
     * @return array
     */
    public function arrayFormatMessage($message): array
    {
        [$text, $level, $category, $timestamp] = $message;
        $traceId = SessionGuid::get();
        $spanId = 'unknown';

        if (null !== $this->span) {
            $traceId = $this->span->getTraceId();
            $spanId = $this->span->getSpanId();
        }

        return [
            'level'       => strtoupper(Logger::getLevelName($level)),
            'eventTime'   => $this->getTime($timestamp),
            'dd.trace_id' => $traceId,
            'dd.span_id'  => $spanId,
            'message'     => $text,
        ];
    }
}
