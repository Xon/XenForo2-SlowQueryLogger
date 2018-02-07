<?php

namespace SV\SlowQueryLogger\Db\Mysqli;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent;
use SV\SlowQueryLogger\Listener;
use XF\Container;
use XF\Db\Exception;

class SlowQueryLogAdapter extends FakeParent
{
    /** @var float */
    protected $slowQuery             = 1.5;
    /** @var float  */
    protected $slowTransaction       = 1;
    /** @var bool */
    protected $reportSlowQueries     = true;
    /** @var int|null */
    protected $startTransactionTime  = null;
    /** @var int|null */
    protected $transactionEndQueryId = null;
    /** @var int */
    protected $startedTransaction    = 0;

    /** @var \XF\Db\AbstractAdapter */
    static $slowQueryDb = null;
    /** @var \XF\Db\AbstractAdapter */
    static $appDb = null;

    public function __construct(array $config, $fullUnicode = false)
    {
        parent::__construct($config, $fullUnicode);
        $options = \XF::options();
        $this->slowQuery = strval(floatval($options->sv_slowquery_threshold)) + 0;
        $this->slowTransaction = strval(floatval($options->sv_slowtransaction_threshold)) + 0;
    }

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
            self::$slowQueryDb = new $adapterClass($dbConfig, $config['fullUnicode']);
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

    /** @var bool  */
    protected $isTransactionStartStatement = false;
    /** @var bool  */
    protected $isTransactionFinishStatement = false;

    public function beginTransaction()
    {
        $this->isTransactionStartStatement = true;
        try
        {
            parent::beginTransaction();
        }
        finally
        {
            $this->isTransactionStartStatement = false;
        }
    }

    public function rollback()
    {
        $this->isTransactionFinishStatement = true;
        try
        {
            parent::rollback();
        }
        finally
        {
            $this->isTransactionFinishStatement = false;
        }
    }

    public function rollbackAll()
    {
        $this->isTransactionFinishStatement = true;
        try
        {
            parent::rollbackAll();
        }
        finally
        {
            $this->isTransactionFinishStatement = false;
        }
    }

    protected function startTransactionTracking()
    {
        if ($this->startedTransaction === 0)
        {
            $this->startTransactionTime = microtime(true);
        }
        $this->startedTransaction += 1;
    }

    /**
     * @return bool
     */
    protected function stopTransactionTracking()
    {
        $this->startedTransaction -= 1;
        if ($this->startedTransaction === 0)
        {
            return true;
        }

        return false;
    }

    public function logQueryExecution($query, array $params = [])
    {
        if (!$this->reportSlowQueries)
        {
            return parent::logQueryExecution($query, $params);
        }
        $captureQueryId = false;
        if ($this->isTransactionStartStatement)
        {
            $this->startTransactionTracking();
        }
        else if ($this->isTransactionFinishStatement)
        {
            $captureQueryId = $this->stopTransactionTracking();
        }
        /*
         * Support manual checkpointing/transactions ?
        else
        {
            switch ($query)
            {
                case 'BEGIN':
                    $this->startTransactionTracking();
                    break;
                case 'ROLLBACK':
                case 'COMMIT':
                    $captureQueryId = $this->stopTransactionTracking();
                    break;

            }
        }
        */


        $oldLogSimpleOnly = $this->logSimpleOnly;
        $oldLogQueries = $this->logQueries;
        $this->logSimpleOnly = !$oldLogQueries || $oldLogSimpleOnly;
        $this->logQueries = true;
        try
        {
            $queryId = parent::logQueryExecution($query, $params);
        }
        finally
        {
            $this->logQueries = $oldLogQueries;
            $this->logSimpleOnly = $oldLogSimpleOnly;
            if ($captureQueryId)
            {
                $this->transactionEndQueryId = $queryId;
            }
        }

        return $queryId;
    }

    protected function getRequestDataForExceptionLog()
    {
        static $requestData = null;
        if ($requestData === null)
        {
            $request = \XF::app()->request();

            $requestData = [
                'url' => $request->getRequestUri(),
                'referrer' => $request->getReferrer(),
                '_GET' => $_GET,
                '_POST' => $request->filterForLog($_POST)
            ];
        }
        return $requestData;
    }

    public function logQueryCompletion($queryId = null)
    {
        /*
        WARNING: this function is called after the query is finished initially executing, but not before all results are fetched.
        Invoking any XF function which touches XenForo_Application::getDb() will likely destroy any unfetched results!!!!
        must call injectSlowQueryDbConn/removeSlowQueryDbConn around any database access
        */
        if (!$this->reportSlowQueries)
        {
            parent::logQueryCompletion($queryId);

            return;
        }

        $oldLogSimpleOnly = $this->logSimpleOnly;
        $oldLogQueries = $this->logQueries;
        $this->logSimpleOnly = !$oldLogQueries || $oldLogSimpleOnly;
        $this->logQueries = true;
        try
        {
            parent::logQueryCompletion($queryId);
            $queryEndTime = microtime(true);

            if ($this->queryCount >= 150 && $oldLogSimpleOnly === null)
            {
                // we haven't specified that we want full details, so switch to reduce memory usage
                $oldLogSimpleOnly = true;
            }

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

            if ($time > $this->slowQuery)
            {
                $requestData = $this->getRequestDataForExceptionLog();
                self::injectSlowQueryDbConn();
                try
                {
                    \XF::logException(new \Exception('Slow query: ' . round($time, 4) . ' seconds' . (empty($requestData['url']) ? '' : ', ' . $requestData['url'])), false, '', true);
                }
                finally
                {
                    self::removeSlowQueryDbConn();
                }
            }
        }
        finally
        {
            $this->logQueries = $oldLogQueries;
            $this->logSimpleOnly = $oldLogSimpleOnly;
        }

        $queryEndTime = $queryEndTime - $this->startTransactionTime;
        if ($this->transactionEndQueryId !== null && $this->transactionEndQueryId === $queryId &&
            $queryEndTime >= $this->slowTransaction)
        {
            $requestData = $this->getRequestDataForExceptionLog();
            $this->transactionEndQueryId = null;
            self::injectSlowQueryDbConn();
            try
            {
                \XF::logException(new Exception('Slow transaction detected: ' . round($queryEndTime, 4) . ' seconds' . (empty($requestData['url']) ? '' : ', ' . $requestData['url'])), false, '', true);
            }
            finally
            {
                self::removeSlowQueryDbConn();
            }
        }
    }
}
