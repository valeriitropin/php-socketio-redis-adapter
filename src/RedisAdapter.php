<?php

namespace ValeriiTropin\Socketio;

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Clue\React\Redis\StreamingClient;
use React\EventLoop\LoopInterface;
use Clue\React\Block;
use React\Promise\Deferred;
use React\Promise;
use React\Promise\Timer;

class RedisAdapter
{
    const REQUEST_TYPE_CLIENTS = 0;
    const REQUEST_TYPE_CLIENT_ROOMS = 1;
    const REQUEST_TYPE_ALL_ROOMS = 2;
    const REQUEST_TYPE_REMOTE_JOIN = 3;
    const REQUEST_TYPE_REMOTE_LEAVE = 4;
    const REQUEST_TYPE_CUSTOM_REQUEST = 5;
    const REQUEST_TYPE_REMOTE_DISCONNECT = 6;

    private $requests = [];
    /* @var StreamingClient */
    private $pub;
    /* @var StreamingClient */
    private $sub;
    /* @var LoopInterface */
    private $loop;
    /* @var string */
    private $prefix;
    /* @var string */
    private $namespace;
    /* @var string */
    private $requestChannel;
    /* @var string */
    private $responseChannel;
    /* @var float */
    private $requestsTimeout;
    /* @var callable */
    private $customHook;

    /**
     * RedisAdapter constructor.
     * @param LoopInterface $loop
     * @param array $options
     * @throws \Exception
     */
    public function __construct(LoopInterface $loop, $options = [])
    {
        $this->loop = $loop;
        $this->prefix = $this->getOption($options, 'prefix', 'socket.io');
        $this->requestsTimeout = $this->getOption($options, 'requestsTimeout', 5);
        $this->namespace = $this->getOption($options, 'namespace', '/');
        $this->pub = $this->getOption($options, 'pubClient');
        $this->sub = $this->getOption($options, 'subClient');
        $this->customHook = $this->getOption($options, 'customHook', function ($data, $callback) {
            $callback();
        });
        $this->requestChannel = $this->prefix . '-request#' . $this->namespace . '#';
        $this->responseChannel = $this->prefix . '-response#' . $this->namespace . '#';

        $uri = $this->getOption($options, 'uri', 'localhost');
        $this->createClients($uri);

        $this->sub->subscribe($this->requestChannel);
        $this->sub->subscribe($this->responseChannel);
        $this->sub->on('message', [$this, 'onRequest']);
    }

    /**
     * @param $options
     * @param $key
     * @param null $default
     * @return mixed
     */
    private function getOption($options, $key, $default = null)
    {
        return isset($options[$key]) ? $options[$key] : $default;
    }

    /**
     * @param string $uri
     * @throws \Exception
     */
    private function createClients($uri)
    {
        $factory = new Factory($this->loop);
        if ($this->pub) {
            $pubPromise = Promise\resolve($this->pub);
        } else {
            $pubPromise = $factory->createClient($uri);
            $pubPromise->then(function (Client $client) {$this->pub = $client;});
        }
        if ($this->sub) {
            $subPromise = Promise\resolve($this->sub);
        } else {
            $subPromise = $factory->createClient($uri);
            $subPromise->then(function (Client $client) {$this->sub = $client;});
        }
        Block\awaitAll([$pubPromise, $subPromise], $this->loop);
    }

    /**
     * @param array $rooms
     * @return Promise\Promise
     */
    public function clients($rooms = [])
    {
        return $this->pub->pubsub('numsub', $this->requestChannel)->then(function($response) use ($rooms) {
            $numsub = $response[1];
            $requestId = uniqid();

            $request = json_encode([
                'requestid' => $requestId,
                'type' => self::REQUEST_TYPE_CLIENTS,
                'rooms' => $rooms,
            ]);

            $deferred = new Deferred(function ($resolve, $reject) use ($requestId) {
                unset($this->requests[$requestId]);
                $reject(new ConnectionTimeoutException('Connection timeout'));
            });

            $this->requests[$requestId] = [
                'type' => self::REQUEST_TYPE_CLIENTS,
                'numsub' => $numsub,
                'messageCount' => 0,
                'clients' => [],
                'deferred' => $deferred,
            ];

            $this->pub->publish($this->requestChannel, $request);
            Timer\timeout($deferred->promise(), $this->requestsTimeout, $this->loop);
            return $deferred->promise();
        });
    }

    /**
     * @param $id
     * @return Promise\Promise|Promise\PromiseInterface
     */
    public function clientRooms($id) {
        $requestId = uniqid();
        $request = [
            'requestid' => $requestId,
            'type' => self::REQUEST_TYPE_CLIENT_ROOMS,
            'sid' => $id,
        ];
        $encodedRequest = json_encode($request);
        $deferred = new Deferred(function ($resolve, $reject) use ($requestId) {
            unset($this->requests[$requestId]);
            $reject(new ConnectionTimeoutException('Connection timeout'));
        });

        $request['deferred'] = $deferred;
        unset($request['requestid'], $request['sid']);
        $this->requests[$requestId] = $request;
        $this->pub->publish($this->requestChannel, $encodedRequest);
        Timer\timeout($deferred->promise(), $this->requestsTimeout, $this->loop);
        return $deferred->promise();
    }

    /**
     * @return Promise\Promise
     */
    public function allRooms() {
        return $this->pub->pubsub('numsub', $this->requestChannel)->then(function($response) {
            $numsub = $response[1];
            $requestId = uniqid();

            $request = [
                'requestid' => $requestId,
                'type' => self::REQUEST_TYPE_ALL_ROOMS,
            ];
            $encodedRequest = json_encode($request);

            $deferred = new Deferred(function ($resolve, $reject) use ($requestId) {
                unset($this->requests[$requestId]);
                $reject(new ConnectionTimeoutException('Connection timeout'));
            });

            $request['deferred'] = $deferred;
            $request['numsub'] = $numsub;
            $request['messageCount'] = 0;
            $request['rooms'] = [];
            unset($request['requestid']);
            $this->requests[$requestId] = $request;
            $this->pub->publish($this->requestChannel, $encodedRequest);

            Timer\timeout($deferred->promise(), $this->requestsTimeout, $this->loop);
            return $deferred->promise();
        });
    }

    /**
     * @param $id
     * @param $room
     * @return Promise\Promise|Promise\PromiseInterface
     */
    public function remoteJoin($id, $room) {
        $requestId = uniqid();
        $request = [
            'requestid' => $requestId,
            'type' => self::REQUEST_TYPE_REMOTE_JOIN,
            'sid' => $id,
            'room' => $room,
        ];
        $encodedRequest = json_encode($request);
        $deferred = new Deferred(function ($resolve, $reject) use ($requestId) {
            unset($this->requests[$requestId]);
            $reject(new ConnectionTimeoutException('Connection timeout'));
        });
        $request['deferred'] = $deferred;
        unset($request['requestid'], $request['sid'], $request['room']);
        $this->requests[$requestId] = $request;
        $this->pub->publish($this->requestChannel, $encodedRequest);

        Timer\timeout($deferred->promise(), $this->requestsTimeout, $this->loop);
        return $deferred->promise();
    }

    /**
     * @param $id
     * @param $room
     * @return Promise\Promise|Promise\PromiseInterface
     */
    public function remoteLeave($id, $room) {
        $requestId = uniqid();
        $request = [
            'requestid' => $requestId,
            'type' => self::REQUEST_TYPE_REMOTE_LEAVE,
            'sid' => $id,
            'room' => $room,
        ];
        $encodedRequest = json_encode($request);

        $deferred = new Deferred(function ($resolve, $reject) use ($requestId) {
            unset($this->requests[$requestId]);
            $reject(new ConnectionTimeoutException('Connection timeout'));
        });
        $request['deferred'] = $deferred;
        unset($request['requestid'], $request['sid'], $request['room']);
        $this->requests[$requestId] = $request;
        $this->pub->publish($this->requestChannel, $encodedRequest);

        Timer\timeout($deferred->promise(), $this->requestsTimeout, $this->loop);
        return $deferred->promise();
    }

    /**
     * @param $id
     * @param $close
     * @return Promise\Promise|Promise\PromiseInterface
     */
    public function remoteDisconnect($id, $close) {
        $requestId = uniqid();
        $request = [
            'requestid' => $requestId,
            'type' => self::REQUEST_TYPE_REMOTE_DISCONNECT,
            'sid' => $id,
            'close' => $close,
        ];
        $encodedRequest = json_encode($request);

        $deferred = new Deferred(function ($resolve, $reject) use ($requestId) {
            unset($this->requests[$requestId]);
            $reject(new ConnectionTimeoutException('Connection timeout'));
        });
        $request['deferred'] = $deferred;
        unset($request['requestid'], $request['sid'], $request['close']);
        $this->requests[$requestId] = $request;
        $this->pub->publish($this->requestChannel, $encodedRequest);

        Timer\timeout($deferred->promise(), $this->requestsTimeout, $this->loop);
        return $deferred->promise();
    }

    /**
     * @param $data
     * @return Promise\Promise
     */
    public function customRequest($data)
    {
        return $this->pub->pubsub('numsub', $this->requestChannel)->then(function($response) use ($data) {
            $numsub = $response[1];
            $requestId = uniqid();

            $request = [
                'requestid' => $requestId,
                'type' => self::REQUEST_TYPE_CUSTOM_REQUEST,
                'data' => $data,
            ];
            $encodedData = json_encode($request);

            $deferred = new Deferred(function ($resolve, $reject) use ($requestId) {
                unset($this->requests[$requestId]);
                $reject(new ConnectionTimeoutException('Connection timeout'));
            });
            $request['deferred'] = $deferred;
            $request['messageCount'] = 0;
            $request['numsub'] = $numsub;
            $request['replies'] = [];
            unset($request['requestid'], $request['data']);
            $this->requests[$requestId] = $request;

            $this->pub->publish($this->requestChannel, $encodedData);
            Timer\timeout($deferred->promise(), $this->requestsTimeout, $this->loop);
            return $deferred->promise();
        });
    }

    /**
     * @param $channel
     * @param $message
     */
    public function onRequest($channel, $message)
    {
        if ($this->channelMatches($channel, $this->responseChannel)) {
            return $this->onResponse($channel, $message);
        } elseif (!$this->channelMatches($channel, $this->requestChannel)) {
            return;
        }

        $request = json_decode($message);
        switch ($request->type) {
            case self::REQUEST_TYPE_CLIENTS:
                $response = json_encode([
                    'requestid' => $request->requestid,
                    'clients' => [],
                ]);
                $this->pub->publish($this->responseChannel, $response);
                break;

            case self::REQUEST_TYPE_CLIENT_ROOMS:
                $response = json_encode([
                    'requestid' => $request->requestid,
                    'rooms' => [],
                ]);
                $this->pub->publish($this->responseChannel, $response);
                break;

            case self::REQUEST_TYPE_ALL_ROOMS:
                $response = json_encode([
                    'requestid' => $request->requestid,
                    'rooms' => [],
                ]);
                $this->pub->publish($this->responseChannel, $response);
                break;

            case self::REQUEST_TYPE_REMOTE_JOIN:
            case self::REQUEST_TYPE_REMOTE_LEAVE:
            case self::REQUEST_TYPE_REMOTE_DISCONNECT:
                $this->pub->publish($this->responseChannel, json_encode([
                    'requestid' => $request->requestid,
                ]));
                break;

            case self::REQUEST_TYPE_CUSTOM_REQUEST:
                call_user_func($this->customHook, $request->data, function ($data = null) use ($request) {
                    $response = json_encode([
                        'requestid' => $request->requestid,
                        'data' => $data,
                    ]);
                    $this->pub->publish($this->responseChannel, $response);
                });
                break;
        }
    }

    /**
     * @param $channel
     * @param $message
     */
    public function onResponse($channel, $message)
    {
        $response = json_decode($message);
        $requestId = $response->requestid;
        if (!isset($this->requests[$response->requestid])) {
            return;
        }

        $request = &$this->requests[$response->requestid];
        switch ($request['type']) {
            case self::REQUEST_TYPE_CLIENTS:
                $request['messageCount']++;
                if (!isset($response->clients)) {
                    return;
                }
                foreach ($response->clients as $client) {
                    $request['clients'][$client] = true;
                }
                if ($request['messageCount'] === $request['numsub']) {
                    unset($this->requests[$requestId]);
                    $request['deferred']->resolve(array_keys($request['clients']));
                }
                break;

            case self::REQUEST_TYPE_CLIENT_ROOMS:
                if (!isset($response->rooms)) {
                    return;
                }
                unset($this->requests[$requestId]);
                $request['deferred']->resolve($response->rooms);
                break;

            case self::REQUEST_TYPE_ALL_ROOMS:
                $request['messageCount']++;
                if (!isset($response->rooms)) {
                    return;
                }
                foreach ($response->rooms as $room) {
                    $request['rooms'][$room] = true;
                }
                if ($request['messageCount'] === $request['numsub']) {
                    unset($this->requests[$requestId]);
                    $request['deferred']->resolve(array_keys($request['rooms']));
                }
                break;

            case self::REQUEST_TYPE_REMOTE_JOIN:
            case self::REQUEST_TYPE_REMOTE_LEAVE:
            case self::REQUEST_TYPE_REMOTE_DISCONNECT:
                unset($this->requests[$response->requestid]);
                $request['deferred']->resolve();
                break;

            case self::REQUEST_TYPE_CUSTOM_REQUEST:
                $request['messageCount']++;
                $request['replies'][] = $response->data;
                if ($request['messageCount'] === $request['numsub']) {
                    unset($this->requests[$requestId]);
                    $request['deferred']->resolve($request['replies']);
                }
                break;
        }
    }

    /**
     * @param $messageChannel
     * @param $subscribedChannel
     * @return bool
     */
    protected function channelMatches($messageChannel, $subscribedChannel)
    {
        return strpos($messageChannel, $subscribedChannel) === 0;
    }

    /**
     * @return LoopInterface
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * @return StreamingClient
     */
    public function getPub() {
        return $this->pub;
    }

    /**
     * @return StreamingClient
     */
    public function getSub() {
        return $this->sub;
    }

    /**
     * @return Promise\Promise
     */
    public function unsubscribeFromRequestChannel()
    {
        return $this->sub->unsubscribe($this->requestChannel);
    }
}
