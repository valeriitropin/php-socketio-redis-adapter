# PHP socket.io redis adapter

Partial port of socket.io redis adapter, async, allows to get clients/rooms and to manage them. The main goal of this project is possibility to communicate between php and socket.io applications. Built on top of [ReactPHP](https://reactphp.org/) components.

## Installation
```
composer require valeriitropin/socketio-redis-adapter
```

## How to use

```php
use React\EventLoop\Factory as ReactFactory;
use ValeriiTropin\Socketio\RedisAdapter;
use Clue\React\Block;
use React\Promise;

$loop = ReactFactory::create();
$client = new RedisAdapter($loop);

$promise = $client->allRooms()->then(function ($rooms) use ($client) {
    $promises = [];
    foreach ($rooms as $room) {
        $promises[] = $client->clients([$room])->then(function ($clients) use ($room) {
            foreach ($clients as $client) {
                echo $room . ' ' . $client . "\n";
            }
        });
    }
    return Promise\all($promises);
})->otherwise(function ($error) {
    echo ($error->getMessage()) . "\n";
});

Block\await($promise, $loop);
```

## API
### RedisAdapter
#### __construct(React\EventLoop\LoopInterface $loop, $options = [])
##### $options: 
- `prefix`: pub/sub events prefix (`socket.io`)
- `requestsTimeout`: timeout in milliseconds, float (`5`)
- `namespace`: socket.io namespace (`/`)
- `pubClient`: pub client
- `subClient`: pub client
- `customHook`: callable
- `uri`: Redis connection string, see [docs](https://github.com/clue/php-redis-react/blob/master/README.md#createclient) (`localhost`)

### clients($rooms = []): React\Promise\Promise

Returns the list of client IDs connected to `rooms` across all nodes.

```php
$adapter->clients($rooms)
    ->then(function ($clients) {
        var_dump($clients);
    })
    ->otherwise(function ($error) {
        echo ($error->getMessage()) . "\n";
    });
```

### clientRooms($id): React\Promise\Promise

Returns the list of rooms the client with the given ID has joined (even on another node).

```php
$adapter->clients($id)
    ->then(function ($rooms) {
        var_dump($rooms);
    })
    ->otherwise(function ($error) {
        echo ($error->getMessage()) . "\n";
    });
```

### allRooms(): React\Promise\Promise

Returns the list of all rooms from all nodes.

```php
$adapter->allRooms()
    ->then(function ($allRooms) {
        var_dump($allRooms);
    })
    ->otherwise(function ($error) {
        echo ($error->getMessage()) . "\n";
    });
```

### remoteJoin($id, $room): React\Promise\Promise

```php
$adapter->remoteJoin($id, $room)
    ->then(function () {})
    ->otherwise(function ($error) {
        echo ($error->getMessage()) . "\n";
    });
```

### remoteLeave($id, $room): React\Promise\Promise

```php
$adapter->remoteLeave($id, $room)
    ->then(function () {})
    ->otherwise(function ($error) {
        echo ($error->getMessage()) . "\n";
    });
```

### remoteDisconnect($id, $close): React\Promise\Promise

```php
$adapter->remoteDisconnect($id, $close)
    ->then(function () {})
    ->otherwise(function ($error) {
        echo ($error->getMessage()) . "\n";
    });
```

### customRequest($data): React\Promise\Promise

Sends a request to every nodes, that will respond through the `customHook` method.

```php
$adapter->customRequest($data)
    ->then(function ($replies) {})
    ->otherwise(function ($error) {
        echo ($error->getMessage()) . "\n";
    });
```

### getLoop(): React\EventLoop\LoopInterface

Returns loop instance.

### getPub(): Clue\React\Redis\StreamingClient

Returns pub client.

### getSub(): Clue\React\Redis\StreamingClient

Returns sub client.

### unsubscribeFromRequestChannel(): React\Promise\Promise

Unsubscribes the adapter instance from request channel.

## Links
 * [Socket.io](https://github.com/socketio/socket.io)
 * [Socket.io Redis adapter](https://github.com/socketio/socket.io-redis)
 * [ReactPHP promises](https://reactphp.org/promise/)
 * [ReactPHP Redis](https://github.com/clue/php-redis-react)
