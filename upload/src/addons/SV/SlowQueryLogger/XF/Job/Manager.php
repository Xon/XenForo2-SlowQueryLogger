<?php

namespace SV\SlowQueryLogger\XF\Job;



/**
 * Extends \XF\Job\Manager
 */
class Manager extends XFCP_Manager
{
    /**
     * @param array $job
     * @param int   $maxRunTime
     * @return \XF\Job\JobResult
     * @throws \Exception
     * @noinspection PhpMissingReturnTypeInspection
     * @noinspection PhpMissingParamTypeInspection
     */
    protected function runJobInternal(array $job, $maxRunTime)
    {
        // skip jobs that are run via front-end bits as it causes too much chatter
        if (!empty(\XF::options()->tooManyQueryPublicOnly))
        {
            $db = \XF::db();
            if ($db instanceof \SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter)
            {
                return $db->suppressCountingQueriesWrapper(function () use ($job, $maxRunTime) {
                    return parent::runJobInternal($job, $maxRunTime);
                });
            }
        }

        return parent::runJobInternal($job, $maxRunTime);
    }
}