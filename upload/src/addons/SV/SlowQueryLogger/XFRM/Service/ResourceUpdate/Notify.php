<?php

namespace SV\SlowQueryLogger\XFRM\Service\ResourceUpdate;

/**
 * Extends \XFRM\Service\ResourceUpdate\Notify
 */
class Notify extends XFCP_Notify
{
    /**
     * @param int|null $timeLimit
     * @return bool
     * @noinspection PhpMissingReturnTypeInspection
     */
    public function notifyAndEnqueue($timeLimit = null)
    {
        if (\XF::options()->sv_toomany_queries_skip_alerts ?? false)
        {
            $db = \XF::db();
            if ($db instanceof \SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter)
            {
                return $db->suppressCountingQueriesWrapper(function () use ($timeLimit) {
                    return parent::notifyAndEnqueue($timeLimit);
                });
            }
        }

        return parent::notifyAndEnqueue($timeLimit);
    }
}