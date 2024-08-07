<?php

namespace SV\SlowQueryLogger\Db\Mysqli;

use SV\SlowQueryLogger\Db\Mysqli\SlowQueryLogAdapter\FakeParent;
use XF\Container;
use XF\Db\AbstractAdapter;
use XF\Db\Exception;
use XF\Db\Mysqli\Adapter as MySqlAdapter;
use XF\Pub\App;
use function microtime;
use function round;

/**
 * @extends MySqlAdapter
 */
class SlowQueryLogAdapter extends FakeParent
{
    /** @var float */
    protected $slowQuery = 1.5;
    /** @var float */
    protected $slowTransaction = 1;
    /** @var bool */
    protected $reportSlowQueries = true;
    /** @var int|null */
    protected $startTransactionTime = null;
    /** @var int|null */
    protected $transactionEndQueryId = null;
    /** @var int */
    protected $startedTransaction = 0;
    /** @var int  */
    protected $interestingQueryCount = 0;
    /** @var bool  */
    protected $countingQueries = false;
    /** @var int  */
    protected $tooManyQueryThreshold = 30;
    /** @var bool */
    protected $tooManyQueryPublicOnly = true;

    /** @var AbstractAdapter */
    protected static $slowQueryDb = null;

    /**
     * SlowQueryLogAdapter constructor.
     *
     * @param array $config
     * @param bool  $fullUnicode
     */
    public function __construct(array $config, $fullUnicode = false)
    {
        parent::__construct($config, $fullUnicode);

        self::withOriginalDbAdapter(function() {
            $this->setupSlowLogger();
        });
    }

    /**
     * Runs in the context of the original database adapter
     * This ensures database queries do not cause a loop
     * @return void
     */
    protected function setupSlowLogger(): void
    {
        $options = \XF::options();

        $this->slowQuery = (float)($options->sv_slowquery_threshold ?? $this->slowQuery);
        $this->slowTransaction = (float)($options->sv_slowtransaction_threshold ?? $this->slowTransaction);
        $this->tooManyQueryThreshold = (int)($options->sv_toomany_queries ?? 30);
        if ($this->tooManyQueryThreshold < 0)
        {
            $this->tooManyQueryThreshold = 0;
        }
        if ($options->sv_toomany_queries_public_only ?? $this->tooManyQueryPublicOnly)
        {
            if (!(\XF::app() instanceof App))
            {
                $this->tooManyQueryThreshold = 0;
            }
            else if (isset($_SERVER['PHP_SELF']) && $_SERVER['PHP_SELF'] === '/job.php')
            {
                // skip job.php since it will very likely cause many queries
                $this->tooManyQueryThreshold = 0;
            }
        }
        if ($this->tooManyQueryThreshold)
        {
            $this->countingQueries = true;
            $dbAdapterStartTime = microtime(true);
            register_shutdown_function(function () use ($dbAdapterStartTime) {
                if ($this->interestingQueryCount > $this->tooManyQueryThreshold)
                {
                    $time = microtime(true) - $dbAdapterStartTime;
                    $requestData = $this->getRequestDataForExceptionLog();
                    self::withOriginalDbAdapter(function() use ($time, $requestData) {
                        \XF::logException(new \Exception('Too many queries query: ' . $this->queryCount . ' in ' . round($time, 4) . ' seconds' . (empty($requestData['url']) ? '' : ', ' . $requestData['url'])), false, '', true);
                    });
                }
            });
        }
    }

    public function suppressCountingQueries() : bool
    {
        $oldValue = $this->countingQueries;
        $this->countingQueries = false;
        return $oldValue;
    }

    public function resumeCountingQueries(bool $oldValue): void
    {
        $this->countingQueries = $oldValue;
    }

    /**
     * @param \Closure $wrapper
     * @return mixed
     */
    public function suppressCountingQueriesWrapper(\Closure $wrapper)
    {
        $oldValue = $this->suppressCountingQueries();
        try
        {
            return $wrapper();
        }
        finally
        {
            $this->resumeCountingQueries($oldValue);
        }
    }

    public function withOriginalDbAdapter(\Closure $wrapper)
    {
        $app = \XF::app();
        if (self::$slowQueryDb === null)
        {
            $config = $app->config();
            $dbConfig = $config['db'];
            $adapterClass = $dbConfig['adapterClass'];
            unset($dbConfig['adapterClass']);

            if ($adapterClass === self::class)
            {
                $adapterClass = MySqlAdapter::class;
            }
            /** @var AbstractAdapter $db */
            self::$slowQueryDb = new $adapterClass($dbConfig, $config['fullUnicode']);
            // prevent recursive profiling
            self::$slowQueryDb->logQueries(false, false);
        }

        $appDb = $app->container()->getOriginal('db');
        /** @var Container $container */
        $container = $app->container();
        $container->set('db', self::$slowQueryDb);

        try
        {
            return $wrapper();
        }
        finally
        {
            /** @var Container $container */
            $container = \XF::app()->container();
            $container->set('db', $appDb);
        }
    }

    /** @var bool */
    protected $isTransactionStartStatement = false;
    /** @var bool */
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

    protected function stopTransactionTracking(): bool
    {
        $this->startedTransaction -= 1;
        if ($this->startedTransaction === 0)
        {
            return true;
        }

        return false;
    }

    /**
     * @param string $query
     * @param array  $params
     * @return int
     * @noinspection PhpMissingReturnTypeInspection
     */
    public function logQueryExecution($query, array $params = [])
    {
        if ($this->countingQueries &&
            !$this->isTransactionStartStatement &&
            !$this->isTransactionFinishStatement)
        {
            $this->interestingQueryCount++;
        }
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
        $queryId = null;
        try
        {
            $queryId = parent::logQueryExecution($query, $params);
        }
        finally
        {
            $this->logQueries = $oldLogQueries;
            $this->logSimpleOnly = $this->logSimpleOnly || $oldLogSimpleOnly;
            if ($captureQueryId && $queryId !== null)
            {
                $this->transactionEndQueryId = $queryId;
            }
        }

        return $queryId;
    }

    protected function getRequestDataForExceptionLog(): ?array
    {
        static $requestData = null;
        if ($requestData === null)
        {
            $request = \XF::app()->request();

            $requestData = [
                'url'      => $request->getRequestUri(),
                'referrer' => $request->getReferrer(),
                '_GET'     => $_GET,
                '_POST'    => $request->filterForLog($_POST)
            ];
        }

        return $requestData;
    }

    /**
     * @param int|null $queryId
     */
    public function logQueryCompletion($queryId = null)
    {
        /*
        WARNING: this function is called after the query is finished initially executing, but not before all results are fetched.
        Invoking any XF function which touches \XF::db() will likely destroy any unfetched results!!!!
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

            if ($this->queryCount >= 150 && !$oldLogSimpleOnly)
            {
                // we haven't specified that we want full details, so switch to reduce memory usage
                $oldLogSimpleOnly = true;
                foreach($this->queryLog as &$query)
                {
                    $query['trace'] = null;
                }
            }

            if (!$queryId)
            {
                $queryId = $this->queryCount;
            }

            $queryInfo = $this->queryLog[$queryId] ?? null;
            if ($queryInfo === null)
            {
                return;
            }

            $time = $queryInfo['complete'] - $queryInfo['start'];

            if ($time > $this->slowQuery)
            {
                $requestData = $this->getRequestDataForExceptionLog();
                self::withOriginalDbAdapter(function() use ($time, $requestData) {
                    \XF::logException(new \Exception('Slow query: ' . round($time, 4) . ' seconds' . (empty($requestData['url']) ? '' : ', ' . $requestData['url'])), false, '', true);
                });
            }
        }
        finally
        {
            $this->logQueries = $oldLogQueries;
            $this->logSimpleOnly = $this->logSimpleOnly || $oldLogSimpleOnly;
        }

        $queryEndTime = $queryEndTime - $this->startTransactionTime;
        if ($this->transactionEndQueryId !== null && $this->transactionEndQueryId === $queryId &&
            $queryEndTime >= $this->slowTransaction)
        {
            $requestData = $this->getRequestDataForExceptionLog();
            $this->transactionEndQueryId = null;
            self::withOriginalDbAdapter(function() use ($queryEndTime, $requestData) {
                \XF::logException(new Exception('Slow transaction detected: ' . round($queryEndTime, 4) . ' seconds' . (empty($requestData['url']) ? '' : ', ' . $requestData['url'])), false, '', true);
            });
        }
    }
}
