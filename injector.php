#!/usr/bin/env php
<?php

declare(strict_types=1);

use Warship\Injector\Cli\Application;

require __DIR__ . '/vendor/autoload.php';

exit(Application::run($argv));
