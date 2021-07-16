<?php

namespace SV\SlowQueryLogger;

/**
 * Class Listener
 *
 * @package SV\SlowQueryLogger
 */
class Listener
{
    /**
     * @param \XF\App $app
     */
    public static function appSetup(\XF\App $app)
    {
        $result = true;
        $fakeParent = 'SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent';
        if (!\class_exists($fakeParent, false))
        {
            $config = $app->config('db');
            $dbAdapterClass = $config['adapterClass'];
            $result = \class_alias($dbAdapterClass, $fakeParent, false);
        }

        // just in case
        if ($result)
        {
            $app->container()->set('db', function ($c) use ($app) {
                $config = $c['config'];

                $dbConfig = $config['db'];
                $adapterClass = 'SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter';
                unset($dbConfig['adapterClass']);

                $db = new $adapterClass($dbConfig, $config['fullUnicode']);
                if (\XF::$debugMode)
                {
                    $db->logQueries(true, !(bool)$app->request()->get('_debug'));
                }

                return $db;
            });
        }
        else
        {
            \XF::logException(new \Exception("Unable to alias existing adapter class for slow query logging!"));
        }
    }
}
