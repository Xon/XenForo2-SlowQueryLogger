<?php

namespace SV\SlowQueryLogger\Db\Mysqli;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent;
use SV\SlowQueryLogger\Listener;
use XF\Db\Exception;

class SlowQueryLogAdapter extends FakeParent
{
	public function logQueryCompletion($queryId = null)
	{
		parent::logQueryCompletion($queryId);

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
				\XF::logException(new \Exception("Slow query: " . sprintf('%.10f seconds', $time / 1000)));
			}
		} catch (Exception $ignored)
		{
		}
	}
}