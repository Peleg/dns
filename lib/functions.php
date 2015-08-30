<?php

namespace Amp\Dns;

use \LibDNS\Messages\MessageFactory;
use \LibDNS\Messages\MessageTypes;
use \LibDNS\Records\QuestionFactory;
use \LibDNS\Encoder\EncoderFactory;
use \LibDNS\Decoder\DecoderFactory;

/**
 * Resolve a DNS name to an IP address
 *
 * Upon success the returned promise resolves to an indexed array of the form:
 *
 *  [string $resolvedIp, int $type, int $ttl]
 *
 * A null $ttl value indicates the DNS name was resolved from the cache or the
 * local hosts file.
 * $type being one constant from Amp\Dns\Record
 *
 * Options:
 *
 *  - "server"       | string   Custom DNS server address in ip or ip:port format
 *  - "timeout"      | int      Default: 3000ms
 *  - "no_hosts"     | bool     Ignore entries in the hosts file
 *  - "reload_hosts" | bool     Reload the hosts file (Default: false), only active when no_hosts not true
 *  - "no_cache"     | bool     Ignore cached DNS response entries
 *  - "types"        | array    Default: [Record::A, Record::AAAA] (only for resolve())
 *  - "recurse"      | bool     Check for DNAME and CNAME records (always active for resolve(), Default: false for query())
 *
 * If the custom per-request "server" option is not present the resolver will
 * use the default from the following built-in constant:
 *
 *  - Amp\Dns\DEFAULT_SERVER
 *
 * @param string $name The hostname to resolve
 * @param array  $options
 * @return \Amp\Promise
 * @TODO add boolean "clear_cache" option flag
 */
function resolve($name, array $options = []) {
    if (!$inAddr = @\inet_pton($name)) {
        if (__isValidHostName($name)) {
            $types = empty($options["types"]) ? [Record::A, Record::AAAA] : $options["types"];
            return __pipeResult(__recurseWithHosts($name, $types, $options), $types);
        } else {
            return new \Amp\Failure(new ResolutionException("Cannot resolve; invalid host name"));
        }
    } else {
        return new \Amp\Success([[$name, isset($inAddr[4]) ? Record::AAAA : Record::A, $ttl = null]]);
    }
}

function query($name, $type, array $options = []) {
    if (!$inAddr = @\inet_pton($name)) {
        if (__isValidHostName($name)) {
            $handler = __NAMESPACE__ . "\\" . (empty($options["recurse"]) ? "__doRecurse" : "__doResolve");
            $types = (array) $type;
            return __pipeResult(\Amp\resolve($handler($name, $types, $options)), $types);
        } else {
            return new \Amp\Failure(new ResolutionException("Cannot resolve; invalid host name"));
        }
    } else {
        return new \Amp\Failure(new ResolutionException("Cannot resolve records from an IP address"));
    }
}

function __isValidHostName($name) {
    $pattern = "/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9]){0,1})(?:\.[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])*$/i";

    return isset($name[253]) ? false : (bool) \preg_match($pattern, $name);
}

// preserve order according to $types
function __pipeResult($promise, array $types) {
    return \Amp\pipe($promise, function (array $result) use ($types) {
        $retval = [];
        foreach ($types as $type) {
            if (isset($result[$type])) {
                $retval = \array_merge($retval, $result[$type]);
                unset($result[$type]);
            }
        }
        return $result ? \array_merge($retval, $result) : $retval;
    });
}

function __recurseWithHosts($name, array $types, $options) {
    // Check for hosts file matches
    if (empty($options["no_hosts"])) {
        static $hosts = null;
        if ($hosts === null || !empty($options["reload_hosts"])) {
            return \Amp\pipe(\Amp\resolve(__loadHostsFile()), function($value) use (&$hosts, $name, $types, $options) {
                $hosts = $value;
                return __recurseWithHosts($name, $types, $options);
            });
        }
        $result = [];
        if (in_array(Record::A, $types) && isset($hosts[Record::A][$name])) {
            $result[Record::A] = [[$hosts[Record::A][$name], Record::A, $ttl = null]];
        }
        if (in_array(Record::AAAA, $types) && isset($hosts[Record::AAAA][$name])) {
            $result[Record::AAAA] = [[$hosts[Record::AAAA][$name], Record::AAAA, $ttl = null]];
        }
        if ($result) {
            return new \Amp\Success($result);
        }
    }

    return \Amp\resolve(__doRecurse($name, $types, $options));
}

function __doRecurse($name, array $types, $options) {
    if (array_intersect($types, [Record::CNAME, Record::DNAME])) {
        throw new ResolutionException("Cannot use recursion for CNAME and DNAME records");
    }

    $types = array_merge($types, [Record::CNAME, Record::DNAME]);
    $lookupName = $name;
    for ($i = 0; $i < 30; $i++) {
        $result = (yield \Amp\resolve(__doResolve($lookupName, $types, $options)));
        if (count($result) > isset($result[Record::CNAME]) + isset($result[Record::DNAME])) {
            unset($result[Record::CNAME], $result[Record::DNAME]);
            yield new \Amp\CoroutineResult($result);
            return;
        }
        // @TODO check for potentially using recursion and iterate over *all* CNAME/DNAME
        // @FIXME check higher level for CNAME?
        foreach ([Record::CNAME, Record::DNAME] as $type) {
            if (isset($result[$type])) {
                list($lookupName) = $result[$type][0];
            }
        }
    }

    throw new ResolutionException("CNAME or DNAME chain too long (possible recursion?)");
}

function __doRequest($state, $uri, $name, $type) {
    $server = __loadExistingServer($state, $uri) ?: __loadNewServer($state, $uri);

    // Get the next available request ID
    do {
        $requestId = $state->requestIdCounter++;
        if ($state->requestIdCounter >= MAX_REQUEST_ID) {
            $state->requestIdCounter = 1;
        }
    } while (isset($state->pendingRequests[$requestId]));

    // Create question record
    $question = $state->questionFactory->create($type);
    $question->setName($name);

    // Create request message
    $request = $state->messageFactory->create(MessageTypes::QUERY);
    $request->getQuestionRecords()->add($question);
    $request->isRecursionDesired(true);
    $request->setID($requestId);

    // Encode request message
    $requestPacket = $state->encoder->encode($request);

    if (substr($uri, 0, 6) == "tcp://") {
        $requestPacket = pack("n", strlen($requestPacket)) . $requestPacket;
    }

    // Send request
    $bytesWritten = \fwrite($server->socket, $requestPacket);
    if ($bytesWritten === false || isset($packet[$bytesWritten])) {
        throw new ResolutionException(
            "Request send failed"
        );
    }

    $promisor = new \Amp\Deferred;
    $server->pendingRequests[$requestId] = true;
    $state->pendingRequests[$requestId] = [$promisor, $name, $type, $uri];

    return $promisor->promise();
}

function __doResolve($name, array $types, $options) {
    static $state;
    $state = $state ?: (yield \Amp\resolve(__init()));

    if (empty($types)) {
        yield new \Amp\CoroutineResult([]);
        return;
    }

    $name = \strtolower($name);
    $result = [];

    // Check for cache hits
    if (empty($options["no_cache"])) {
        foreach ($types as $k => $type) {
            $cacheKey = "$name#$type";
            if (yield $state->arrayCache->has($cacheKey)) {
                $result[$type] = (yield $state->arrayCache->get($cacheKey));
                unset($types[$k]);
            }
        }
        if (empty($types)) {
            yield new \Amp\CoroutineResult($result);
            return;
        }
    }

    $timeout = empty($options["timeout"]) ? DEFAULT_TIMEOUT : (int) $options["timeout"];

    $uri = empty($options["server"])
        ? "udp://" . DEFAULT_SERVER . ":" . DEFAULT_PORT
        : __parseCustomServerUri($options["server"])
    ;

    foreach ($types as $type) {
        $promises[] = __doRequest($state, $uri, $name, $type);
    }

    try {
        list( , $resultArr) = (yield \Amp\timeout(\Amp\some($promises), $timeout));
        foreach ($resultArr as $value) {
            $result += $value;
        }
        yield new \Amp\CoroutineResult($result);
    } catch (\Amp\TimeoutException $e) {
        throw new TimeoutException(
            "Name resolution timed out for {$name}"
        );
    }
}

function __init() {
    $state = new \StdClass;
    $state->messageFactory = new MessageFactory;
    $state->questionFactory = new QuestionFactory;
    $state->encoder = (new EncoderFactory)->create();
    $state->decoder = (new DecoderFactory)->create();
    $state->arrayCache = new \Amp\Cache\ArrayCache;
    $state->requestIdCounter = 1;
    $state->pendingRequests = [];
    $state->serverIdMap = [];
    $state->serverUriMap = [];
    $state->serverIdTimeoutMap = [];
    $state->now = \time();
    $state->serverTimeoutWatcher = \Amp\repeat(function ($watcherId) use ($state) {
        $state->now = $now = \time();
        foreach ($state->serverIdTimeoutMap as $id => $expiry) {
            if ($now > $expiry) {
                __unloadServer($state, $id);
            }
        }
        if (empty($state->serverIdMap)) {
            \Amp\disable($watcherId);
        }
    }, 1000, $options = [
        "enable" => true,
        "keep_alive" => false,
    ]);

    yield new \Amp\CoroutineResult($state);
}

function __loadHostsFile($path = null) {
    $data = [];
    if (empty($path)) {
        $path = \stripos(PHP_OS, 'win') === 0
            ? 'C:\Windows\system32\drivers\etc\hosts'
            : '/etc/hosts'
        ;
    }
    try {
        $contents = (yield \Amp\Filesystem\get($path));
    } catch (\Exception $e) {
        yield new \Amp\CoroutineResult($data);
        return;
    }
    $lines = \array_filter(\array_map("trim", \explode("\n", $contents)));
    foreach ($lines as $line) {
        if ($line[0] === "#") {
            continue;
        }
        $parts = \preg_split('/\s+/', $line);
        if (!($ip = @\inet_pton($parts[0]))) {
            continue;
        } elseif (isset($ip[4])) {
            $key = Record::AAAA;
        } else {
            $key = Record::A;
        }
        for ($i = 1, $l = \count($parts); $i < $l; $i++) {
            if (__isValidHostName($parts[$i])) {
                $data[$key][strtolower($parts[$i])] = $parts[0];
            }
        }
    }

    yield new \Amp\CoroutineResult($data);
}

function __parseCustomServerUri($uri) {
    if (!\is_string($uri)) {
        throw new ResolutionException(
            "Invalid server address (". gettype($uri) ."); string IP required"
        );
    }
    if (($colonPos = strrpos(":", $uri)) !== false) {
        $addr = \substr($uri, 0, $colonPos);
        $port = \substr($uri, $colonPos);
    } else {
        $addr = $uri;
        $port = DEFAULT_PORT;
    }
    $addr = trim($addr, "[]");
    if (!$inAddr = @\inet_pton($addr)) {
        throw new ResolutionException(
            "Invalid server URI; IP address required"
        );
    }

    return isset($inAddr[4]) ? "udp://[{$addr}]:{$port}" : "udp://{$addr}:{$port}";
}

function __loadExistingServer($state, $uri) {
    if (empty($state->serverUriMap[$uri])) {
        return;
    }

    $server = $state->serverUriMap[$uri];
    if (\is_resource($server->socket)) {
        unset($state->serverIdTimeoutMap[$server->id]);
        \Amp\enable($server->watcherId);
        return $server;
    }
    __unloadServer($state, $server->id);
}

function __loadNewServer($state, $uri) {
    if (!$socket = @\stream_socket_client($uri, $errno, $errstr)) {
        throw new ResolutionException(sprintf(
            "Connection to %s failed: [Error #%d] %s",
            $uri,
            $errno,
            $errstr
        ));
    }

    \stream_set_blocking($socket, false);
    $id = (int) $socket;
    $server = new \StdClass;
    $server->id = $id;
    $server->uri = $uri;
    $server->socket = $socket;
    $server->buffer = "";
    $server->length = INF;
    $server->pendingRequests = [];
    $server->watcherId = \Amp\onReadable($socket, "Amp\Dns\__onReadable", [
        "enable" => true,
        "keep_alive" => true,
        "cb_data" => $state,
    ]);
    $state->serverIdMap[$id] = $server;
    $state->serverUriMap[$uri] = $server;

    return $server;
}

function __unloadServer($state, $serverId, $error = null) {
    $server = $state->serverIdMap[$serverId];
    \Amp\cancel($server->watcherId);
    unset(
        $state->serverIdMap[$serverId],
        $state->serverUriMap[$server->uri]
    );
    if (\is_resource($server->socket)) {
        @\fclose($server->socket);
    }
    if ($error && $server->pendingRequests) {
        foreach (array_keys($server->pendingRequests) as $requestId) {
            list($promisor) = $state->pendingRequests[$requestId];
            $promisor->fail($error);
        }
    }
}

function __onReadable($watcherId, $socket, $state) {
    $serverId = (int) $socket;
    $packet = @\fread($socket, 512);
    if ($packet != "") {
        $server = $state->serverIdMap[$serverId];
        if (\substr($server->uri, 0, 6) == "tcp://") {
            if ($server->length == INF) {
                $server->length = unpack("n", $packet)[1];
                $packet = substr($packet, 2);
            }
            $server->buffer .= $packet;
            while ($server->length <= \strlen($server->buffer)) {
                __decodeResponsePacket($state, $serverId, substr($server->buffer, 0, $server->length));
                $server->buffer = substr($server->buffer, $server->length);
                if (\strlen($server->buffer) >= 2 + $server->length) {
                    $server->length = unpack("n", $server->buffer)[1];
                    $server->buffer = substr($server->buffer, 2);
                } else {
                    $server->length = INF;
                }
            }
        } else {
            __decodeResponsePacket($state, $serverId, $packet);
        }
    } else {
        __unloadServer($state, $serverId, new ResolutionException(
            "Server connection failed"
        ));
    }
}

function __decodeResponsePacket($state, $serverId, $packet) {
    try {
        $response = $state->decoder->decode($packet);
        $requestId = $response->getID();
        $responseCode = $response->getResponseCode();
        $responseType = $response->getType();

        if ($responseCode !== 0) {
            __finalizeResult($state, $serverId, $requestId, new ResolutionException(
                "Server returned error code: {$responseCode}"
            ));
        } elseif ($responseType !== MessageTypes::RESPONSE) {
            __unloadServer($state, $serverId, new ResolutionException(
                "Invalid server reply; expected RESPONSE but received QUERY"
            ));
        } else {
            __processDecodedResponse($state, $serverId, $requestId, $response);
        }
    } catch (\Exception $e) {
        __unloadServer($state, $serverId, new ResolutionException(
            "Response decode error", 0, $e
        ));
    }
}

function __processDecodedResponse($state, $serverId, $requestId, $response) {
    list($promisor, $name, $type, $uri) = $state->pendingRequests[$requestId];

    // Retry via tcp if message has been truncated
    if ($response->isTruncated()) {
        if (\substr($uri, 0, 6) != "tcp://") {
            $uri = \preg_replace("#[a-z.]+://#", "tcp://", $uri);
            $promisor->succeed(__doRequest($state, $uri, $name, $type));
        } else {
            __finalizeResult($state, $serverId, $requestId, new ResolutionException(
                "Server returned truncated response"
            ));
        }
        return;
    }

    $answers = $response->getAnswerRecords();
    foreach ($answers as $record) {
        $result[$record->getType()][] = [(string) $record->getData(), $record->getType(), $record->getTTL()];
    }
    if (empty($result)) {
        __finalizeResult($state, $serverId, $requestId, new NoRecordException(
            "No records returned for {$name}"
        ));
    } else {
        __finalizeResult($state, $serverId, $requestId, $error = null, $result);
    }
}

function __finalizeResult($state, $serverId, $requestId, $error = null, $result = null) {
    if (empty($state->pendingRequests[$requestId])) {
        return;
    }

    list($promisor, $name) = $state->pendingRequests[$requestId];
    $server = $state->serverIdMap[$serverId];
    unset(
        $state->pendingRequests[$requestId],
        $server->pendingRequests[$requestId]
    );
    if (empty($server->pendingRequests)) {
        $state->serverIdTimeoutMap[$server->id] = $state->now + IDLE_TIMEOUT;
        \Amp\disable($server->watcherId);
        \Amp\enable($state->serverTimeoutWatcher);
    }
    if ($error) {
        $promisor->fail($error);
    } else {
        foreach ($result as $type => $records) {
            $minttl = INF;
            foreach ($records as list( , $ttl)) {
                if ($ttl && $minttl > $ttl) {
                    $minttl = $ttl;
                }
            }
            $state->arrayCache->set("$name#$type", $records, $minttl);
        }
        $promisor->succeed($result);
    }
}
