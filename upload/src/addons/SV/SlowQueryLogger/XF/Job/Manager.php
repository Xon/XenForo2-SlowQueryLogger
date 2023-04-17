<?php

namespace SV\SlowQueryLogger\XF\Job;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter;
use XF\Job\JobResult;

/**
 * Extends \XF\Job\Manager
 */
class Manager extends XFCP_Manager
{
    /**
     * @param array $job
     * @param int   $maxRunTime
     * @return JobResult
     * @throws \Exception
     * @noinspection PhpMissingReturnTypeInspection
     */
    protected function runJobInternal(array $job, $maxRunTime)
    {
        // skip jobs that are run via front-end bits as it causes too much chatter
        if (\XF::options()->tooManyQueryPublicOnly ?? false)
        {
            $db = \XF::db();
            if ($db instanceof SlowQueryLogAdapter)
            {
                return $db->suppressCountingQueriesWrapper(function () use ($job, $maxRunTime) {
                    return parent::runJobInternal($job, $maxRunTime);
                });
            }
        }

        return parent::runJobInternal($job, $maxRunTime);
    }
}