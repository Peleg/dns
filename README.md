# dns

[![Build Status](https://img.shields.io/travis/amphp/dns/master.svg?style=flat-square)](https://travis-ci.org/amphp/dns)
[![CoverageStatus](https://img.shields.io/coveralls/amphp/dns/master.svg?style=flat-square)](https://coveralls.io/github/amphp/dns?branch=master)
![Unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)


`amphp/dns` provides asynchronous DNS name resolution based on the [`amp`](https://github.com/amphp/amp)
concurrency framework.

**Required PHP Version**

- PHP 5.5+

**Installation**

```bash
$ composer require amphp/dns:dev-master
```

**Example**

```php
<?php

require __DIR__ . '/vendor/autoload.php';

Amp\run(function () {
    $githubIpv4 = (yield Amp\Dns\resolve("github.com"));
    var_dump($githubIpv4);

    $googleIpv4 = Amp\Dns\resolve("google.com");
    $googleIpv6 = Amp\Dns\resolve("google.com", $options = [
        "mode" => Amp\Dns\MODE_INET6
    ]);

    $firstGoogleResult = (yield Amp\first([$ipv4Result, $ipv6Result]));
    var_dump($firstGoogleResult);
});
```
