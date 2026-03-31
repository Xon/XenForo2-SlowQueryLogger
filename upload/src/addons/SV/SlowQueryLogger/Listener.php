<?php

namespace SV\SlowQueryLogger;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter;
use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent;
use XF\App;
use function class_alias;
use function class_exists;
use function property_exists;

abstract class Listener
{
    private function __construct() {}

    public static function appSetup(App $app): void
    {
        $result = true;
        $fakeParent = FakeParent::class;
        if (!class_exists($fakeParent, false))
        {
            $config = $app->config('db');
            $dbAdapterClass = $config['adapterClass'];
            $result = class_alias($dbAdapterClass, $fakeParent, false);
        }

        // just in case
        if ($result)
        {
            $c = $app->container();
            $c->set('db', function ($c) use ($app) {
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

            $keys = [
                'permission.cache',
                // need to patch the \XF\Mvc\Entity\Manager::$db is something has touched \XF::em() before this code runs
                // otherwise the transaction state can get out of sync and then causes really *weird* bugs
                'em',
                // todo; check other built-ins that may require patching
            ];

            foreach ($keys as $key)
            {
                if ($c->isCached($key))
                {
                    self::patchDbProperty($c[$key]);
                }
            }
        }
        else
        {
            \XF::logException(new \Exception('Unable to alias existing adapter class for slow query logging!'));
        }
    }

    protected static function patchDbProperty(object $obj): void
    {
        \Closure::bind(function () {
            if (property_exists($this, 'db'))
            {
                $this->db = \XF::db();
            }
        }, $obj, $obj)();
    }
}