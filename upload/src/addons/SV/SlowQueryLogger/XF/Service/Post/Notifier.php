<?php

namespace SV\SlowQueryLogger\XF\Service\Post;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter;

/**
 * @Extends \XF\Service\Post\Notifier
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
        if (\XF::options()->sv_toomany_queries_skip_alerts ?? false)
        {
            $db = \XF::db();
            if ($db instanceof SlowQueryLogAdapter)
            {
                return $db->suppressCountingQueriesWrapper(function () use ($timeLimit) {
                    return parent::notifyAndEnqueue($timeLimit);
                });
            }
        }

        return parent::notifyAndEnqueue($timeLimit);
    }
}