<?php

namespace Amp\Dns;

use Amp\Dns\Cache\MemoryCache;
use Amp\Dns\Cache\APCCache;
use Amp\Dns\Cache\MemcachedCache;
use Memcached;

class CacheFactory
{
    /**
     * Get an instance of the best available caching back-end that does not have any dependencies
     *
     * @return Cache
     */
    public function select()
    {
        if (class_exists('Memcached') && $memcached = new Memcached) {
            $host = getenv('MEMCACHED_HOST') ?: '127.0.0.1';
            $port = getenv('MEMCACHED_PORT') ?: 11211;
            $weight = 100;

            $memcached->addServer($host, $port, $weight);

            if (is_array($memcached->getVersion())) // established connection
                return new MemcachedCache($memcached);
        }

        if (extension_loaded('apc') && ini_get("apc.enabled") && @apc_cache_info()) {
            return new APCCache;
        }

        return new MemoryCache;
    }
}
