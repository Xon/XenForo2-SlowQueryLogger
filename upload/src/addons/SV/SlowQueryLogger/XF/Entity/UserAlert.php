<?php

namespace SV\SlowQueryLogger\XF\Entity;

/**
 * Extends \XF\Entity\UserAlert
 */
class UserAlert extends XFCP_UserAlert
{
    protected $svSuppressCountingQueries = false;

    protected function _preSave()
    {
        parent::_preSave();
        if (\XF::options()->sv_toomany_queries_skip_alerts ?? false)
        {
            $db = \XF::db();
            if ($db instanceof \SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter)
            {
                $this->svSuppressCountingQueries = $db->suppressCountingQueries();
            }
        }
    }

    /**
     * @param array $newDbValues
     */
    protected function _saveCleanUp(array $newDbValues)
    {
        parent::_saveCleanUp($newDbValues);

        if ($this->svSuppressCountingQueries && (\XF::options()->sv_toomany_queries_skip_alerts ?? false))
        {
            $db = \XF::db();
            if ($db instanceof \SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter)
            {
                $db->resumeCountingQueries($this->svSuppressCountingQueries);
            }
        }
    }
}