<?php

namespace Amp\Dns\Cache;

use Amp\Dns\Cache;

class MemoryCache implements Cache {

    /**
     * Internal data store for cache values
     *
     * @var array
     */
    static private $recordsByTypeAndName = [];

    /**
     * Attempt to retrieve a value from the cache
     *
     * @param string $name
     * @param int $type
     * @param callable $callback
     */
    public function get($name, $type, callable $callback) {
        if (isset(static::$recordsByTypeAndName[$type][$name])) {
            list($value, $expireTime) = static::$recordsByTypeAndName[$type][$name];

            if ($expireTime > time()) {
                $callback(true, $value);
                return;
            }

            unset(static::$recordsByTypeAndName[$type][$name]);
        }

        $callback(false, null);
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param string $name
     * @param int $type
     * @param string $addr
     * @param int $ttl
     */
    public function store($name, $type, $addr, $ttl = null) {
        if ($addr === null) {
            throw new \InvalidArgumentException('Caching null addresses is disallowed');
        }

        if ($ttl === null) {
            $ttl = self::DEFAULT_TTL;
        }

        static::$recordsByTypeAndName[$type][$name] = [$addr, time() + $ttl];
    }

    /**
     * Deletes an entry from the cache.
     *
     * @param string $name
     * @param int $type
     */
    public function delete($name, $type = null) {
        if ($type !== null) {
            unset(static::$recordsByTypeAndName[$type][$name]);
        } else {
            /* this approach is to avoid COW of the whole record store
               do not "optimise" it! */
            foreach (array_keys(static::$recordsByTypeAndName) as $type) {
                unset(static::$recordsByTypeAndName[$type][$name]);
            }
        }
    }

    /**
     * Delete all expired records from the cache
     */
    public function collectGarbage() {
        /* this approach is to avoid COW of the whole record store
           do not "optimise" it! */
        $now = time();
        $toDelete = [];

        foreach (static::$recordsByTypeAndName as $type => $records) {
            foreach ($records as $name => $record) {
                if ($record[1] <= $now) {
                    $toDelete[] = [$type, $name];
                }
            }
        }

        foreach ($toDelete as $record) {
            unset(static::$recordsByTypeAndName[$record[0]][$record[1]]);
        }
    }
}
