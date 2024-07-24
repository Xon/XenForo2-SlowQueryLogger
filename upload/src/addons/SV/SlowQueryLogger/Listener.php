<?php

namespace SV\SlowQueryLogger;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter;
use XF\App;
use function class_alias;
use function class_exists;

abstract class Listener
{
    private function __construct() {}

    public static function appSetup(App $app): void
    {
        $result = true;
        $fakeParent = 'SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent';
        if (!class_exists($fakeParent, false))
        {
            $config = $app->config('db');
            $dbAdapterClass = $config['adapterClass'];
            $result = class_alias($dbAdapterClass, $fakeParent, false);
        }

        // just in case
        if ($result)
        {
            $app->container()->set('db', function ($c) use ($app) {
                $config = $c['config'];

                $dbConfig = $config['db'];
                $adapterClass = SlowQueryLogAdapter::class;
                unset($dbConfig['adapterClass']);

                $db = new $adapterClass($dbConfig, $config['fullUnicode']);
                if (\XF::$debugMode)
                {
                    $debugFlag = (bool)$app->request()->get('_debug');
                    $db->logQueries(true, !$debugFlag);
                }

                return $db;
            });
        }
        else
        {
            \XF::logException(new \Exception('Unable to alias existing adapter class for slow query logging!'));
        }
    }
}
