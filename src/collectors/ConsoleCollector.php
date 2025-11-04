<?php

namespace tzabzlat\yii2sentry\collectors;

use tzabzlat\yii2sentry\SentryComponent;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Yii;
use yii\base\Event;
use yii\console\Application;
use yii\console\Controller;

/**
 * ConsoleCollector collects console command information and sends it to Sentry
 * Creates and manages transactions for console commands
 */
class ConsoleCollector extends BaseCollector
{
    /**
     * @var float Timestamp when the command started
     */
    private $commandStartTime;

    /**
     * @var array Command data to be sent to Sentry
     */
    private $commandData = [];

    /**
     * @var \Sentry\Tracing\Transaction Transaction for this command
     */
    private $transaction;

    /**
     * Attaches event handlers to collect console command information
     */
    public function attach(SentryComponent $sentryComponent): bool
    {
        parent::attach($sentryComponent);

        if (!Yii::$app instanceof Application) {
            Yii::info('ConsoleCollector only works with Console Application', $this->logCategory);
            return false;
        }

        $this->commandStartTime = defined('YII_BEGIN_TIME') ? YII_BEGIN_TIME : microtime(true);

        // Start transaction for this console command
        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function ($event) {
            $this->transaction = $this->startCommandTransaction($event);
        });

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, function () {
            $this->finishCommandTracking();
        });

        return true;
    }

    function setTags(Scope $scope): void
    {
        $scope->setTag('type', 'console');
        
        if ($this->commandData) {
            if (isset($this->commandData['command'])) {
                $scope->setTag('console.command', $this->commandData['command']);
            }
            if (isset($this->commandData['action'])) {
                $scope->setTag('console.action', $this->commandData['action']);
            }
        }
    }

    /**
     * Starts a transaction for the current console command
     *
     * @param Event $event
     * @return \Sentry\Tracing\Transaction|null
     */
    protected function startCommandTransaction($event): ?Transaction
    {
        try {
            $controller = $event->sender;
            $action = $event->action;

            if (!$controller || !$action) {
                return null;
            }

            $context = new TransactionContext();

            $commandName = get_class($controller) . '::' . $action->id;
            $shortName = $controller->id . '/' . $action->id;

            $context->setName($commandName);
            $context->setOp('console.command');

            if (defined('YII_BEGIN_TIME')) {
                $context->setStartTimestamp(YII_BEGIN_TIME);
            }

            $transaction = SentrySdk::getCurrentHub()->startTransaction($context);

            if (!$transaction) {
                Yii::error('Failed to start transaction for console command', $this->logCategory);
                return null;
            }

            $this->commandData = [
                'command' => $controller->id,
                'action' => $action->id,
                'route' => $shortName,
                'class' => get_class($controller),
                'params' => Yii::$app->request->getParams(),
                'start_time' => $this->commandStartTime,
            ];

            $transaction->setData([
                'command' => $shortName,
                'action' => $action->id,
                'controller' => get_class($controller),
                'params' => $this->sanitizeParams(Yii::$app->request->getParams()),
            ]);

            SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($transaction) {
                $scope->setSpan($transaction);
            });

            $this->addBreadcrumb(
                "Console Command: {$shortName}",
                [
                    'command' => $controller->id,
                    'action' => $action->id,
                ],
                Breadcrumb::LEVEL_INFO,
                'console.command'
            );

            Yii::info('Started console command transaction: ' . $commandName, $this->logCategory);

            return $transaction;
        } catch (\Throwable $e) {
            Yii::error('Error starting Sentry transaction for console command: ' . $e->getMessage(), $this->logCategory);
            return null;
        }
    }

    /**
     * Finishes the command tracking and records the command duration
     */
    protected function finishCommandTracking()
    {
        if (!$this->transaction) {
            return;
        }

        $duration = microtime(true) - $this->commandStartTime;
        $this->commandData['duration'] = round($duration * 1000, 2); // Convert to milliseconds

        $finalData = [
            'command_final' => $this->commandData,
            'memory_peak' => memory_get_peak_usage(true),
            'processing_time' => round($duration * 1000, 2) . ' ms',
            'performance' => [
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'duration' => round($duration * 1000, 2) . ' ms',
            ]
        ];

        $this->transaction->setData(array_merge(
            $this->transaction->getData(),
            $finalData
        ));

        // Set transaction status as OK (can be overridden by error handler)
        $this->transaction->setStatus(SpanStatus::ok());

        $this->addBreadcrumb(
            'Console command completed',
            [
                'duration' => round($duration * 1000, 2) . ' ms',
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            ],
            Breadcrumb::LEVEL_INFO,
            'console.complete'
        );

        Yii::info('Finishing console command transaction', $this->logCategory);

        // Finish transaction
        $this->transaction->finish();
    }

    /**
     * Sanitizes parameters to remove sensitive information
     *
     * @param array $params The parameters to sanitize
     * @return array
     */
    protected function sanitizeParams($params)
    {
        $sensitiveKeys = [
            'password',
            'passwd',
            'pass',
            'pwd',
            'secret',
            'token',
            'api_key',
            'apikey',
            'access_token',
            'auth',
            'credentials',
        ];

        $sanitized = [];

        foreach ($params as $key => $value) {
            $lowerKey = strtolower((string)$key);

            if (in_array($lowerKey, $sensitiveKeys) ||
                strpos($lowerKey, 'password') !== false ||
                strpos($lowerKey, 'token') !== false ||
                strpos($lowerKey, 'secret') !== false) {
                $sanitized[$key] = '[HIDDEN]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeParams($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Formats bytes to human-readable format
     *
     * @param int $bytes Number of bytes
     * @param int $precision Precision of formatting
     * @return string
     */
    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

