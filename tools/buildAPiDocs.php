<?php

namespace PKP\tools;

use OpenApi\Generator;
use PKP\cliTool\CommandLineTool;

define('APP_ROOT', dirname(__FILE__, 4));
require(APP_ROOT . '/tools/bootstrap.php');

class BuildApiDocs extends CommandLineTool
{
    public function execute(): void
    {
        $directoriesToScan = [
            APP_ROOT . '/classes',
            APP_ROOT . '/api',
//            APP_ROOT . '/plugins',
            APP_ROOT . '/lib/pkp/classes',
            APP_ROOT . '/lib/pkp/api',
//            APP_ROOT . '/lib/pkp/plugins',
        ];

        $openApi = Generator::scan($directoriesToScan);
        dump($openApi->toJson());
    }
}

try {
    $tool = new BuildApiDocs($argv ?? []);
    $tool->execute();
} catch (\Throwable $exception) {
    dump($exception);
}
