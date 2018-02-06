<?php

namespace SV\SlowQueryLogger\Db\Mysqli;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent;
use SV\SlowQueryLogger\Listener;
use XF\Container;
use XF\Db\Exception;

class SlowQueryLogAdapter extends FakeParent
{
	protected static $logging = false;

    /** @var \XF\Db\AbstractAdapter */
    static $slowQueryDb = null;
    /** @var \XF\Db\AbstractAdapter */
    static $appDb = null;

    public static function injectSlowQueryDbConn()
    {
        $app = \XF::app();
        if (self::$slowQueryDb === null)
        {
            $config = $app->config();
            $dbConfig = $config['db'];
            $adapterClass = $dbConfig['adapterClass'];
            unset($dbConfig['adapterClass']);

            /** @var \XF\Db\AbstractAdapter $db */
            self::$slowQueryDb = new $adapterClass($dbConfig, $config->fullUnicode);
            // prevent recursive profiling
            self::$slowQueryDb->logQueries(false, false);
        }
        if (self::$appDb !== null)
        {
            throw new \LogicException('Nesting calls to injectSlowQueryDbConn is not supported');
        }


        self::$appDb = $app->db();
        /** @var Container $container */
        $container = $app->container();
        $container->set('db', self::$slowQueryDb);
    }

    public static function removeSlowQueryDbConn()
    {
        if (self::$appDb === null)
        {
            throw new \LogicException('Must call injectSlowQueryDbConn before removeSlowQueryDbConn');
        }
        /** @var Container $container */
        $container = \XF::app()->container();
        $container->set('db', self::$appDb);
        self::$appDb = null;
    }

	public function logQueryCompletion($queryId = null)
	{
        /*
        WARNING: this function is called after the query is finished initially executing, but not before all results are fetched.
        Invoking any XF function which touches XenForo_Application::getDb() will likely destroy any unfetched results!!!!
        must call injectSlowQueryDbConn/removeSlowQueryDbConn around any database access
        */
		parent::logQueryCompletion($queryId);

		if (self::$logging)
		{
			return;
		}

		self::$logging = true;

		try
		{
			if (!$queryId)
			{
				$queryId = $this->queryCount;
			}
			if (!isset($this->queryLog[$queryId]))
			{
				return;
			}

			$queryInfo = $this->queryLog[$queryId];

			$time = $queryInfo['complete'] - $queryInfo['start'];

			if (Listener::$queryLimit && ($time) > Listener::$queryLimit * 1000)
			{
                self::injectSlowQueryDbConn();
                try
                {
				    \XF::logException(new \Exception("Slow query: " . sprintf('%.10f seconds', $time / 1000)), false);
                }
                finally
                {
                    self::removeSlowQueryDbConn();
                }
			}
		}
		catch (Exception $ignored)
		{
		}
		finally
		{
			self::$logging = false;
		}
	}
}
