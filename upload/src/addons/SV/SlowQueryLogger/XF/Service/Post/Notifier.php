<?php

namespace SV\SlowQueryLogger\XF\Service\Post;



/**
 * Extends \XF\Service\Post\Notifier
 */
class Notifier extends XFCP_Notifier
{
    /**
     * @param int|null $timeLimit
     * @return bool
     * @noinspection PhpMissingReturnTypeInspection
     */
    public function notifyAndEnqueue($timeLimit = null)
    {
        if (!empty(\XF::options()->sv_toomany_queries_skip_alerts))
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