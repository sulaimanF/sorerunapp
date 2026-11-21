<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/stage11-environment.php';
$app = bootStage11(live: true);
exit($app->make(Kernel::class)->handle(new ArgvInput($argv), new ConsoleOutput));
