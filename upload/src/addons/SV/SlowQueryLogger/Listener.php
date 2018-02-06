<?php

namespace SV\SlowQueryLogger;

class Listener
{
	public static $queryLimit = null;

	public static function appSetup(\XF\App $app)
	{
		$result = true;
        $fakeParent = 'SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent';
		if (!class_exists($fakeParent, false))
		{
            /** @noinspection PhpUnusedLocalVariableInspection */
            $dbAdapterClass = $app->config('db')->adapterClass;
			$result = class_alias($dbAdapterClass, $fakeParent, false);
		}

		// just in case
		if ($result)
		{
			self::$queryLimit = \XF::options()->sv_slowquery_threshold;

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
