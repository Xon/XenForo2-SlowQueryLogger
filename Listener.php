<?php

namespace SV\SlowQueryLogger;

use XF\Db\Exception;

class Listener
{
	public static $queryLimit = null;

	public static function appSetup(\XF\App $app)
	{
		$dbAdapterClass = $app->config('db')['adapterClass'];

		$result = true;

		if (!class_exists('SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent', false))
		{
			$result = class_alias($dbAdapterClass, 'SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent', false);
		}

		// just in case
		if ($result)
		{
			self::$queryLimit = \XF::options()->sv_slowquery_log_threshold;

			$app->container()->set('db', function ($c)
			{
				$config = $c['config'];

				$dbConfig = $config['db'];
				$adapterClass = 'SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter';
				unset($dbConfig['adapterClass']);

				/** @var \XF\Db\AbstractAdapter $db */
				$db = new $adapterClass($dbConfig, $config['fullUnicode']);
				if (\XF::$debugMode)
				{
					$db->logQueries(true);
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