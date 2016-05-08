<?php

namespace Drupal\Core;

use Drupal\Component\Utility\Timer;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Psr\Log\LoggerInterface;

/**
 * The Drupal core Cron service.
 */
class Cron implements CronInterface {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The lock service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The queue plugin manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * Constructs a cron object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *    The account switching service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface
   *   The queue plugin manager.
   */
  public function __construct(ModuleHandlerInterface $module_handler, LockBackendInterface $lock, QueueFactory $queue_factory, StateInterface $state, AccountSwitcherInterface $account_switcher, LoggerInterface $logger, QueueWorkerManagerInterface $queue_manager) {
    $this->moduleHandler = $module_handler;
    $this->lock = $lock;
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->accountSwitcher = $account_switcher;
    $this->logger = $logger;
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    // Allow execution to continue even if the request gets cancelled.
    @ignore_user_abort(TRUE);

    // Force the current user to anonymous to ensure consistent permissions on
    // cron runs.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());

    // Try to allocate enough time to run all the hook_cron implementations.
    drupal_set_time_limit(240);

    $return = FALSE;

    // Try to acquire cron lock.
    if (!$this->lock->acquire('cron', 900.0)) {
      // Cron is still running normally.
      $this->logger->warning('Attempting to re-run cron while it is already running.');
    }
    else {
      $this->invokeCronHandlers();
      $this->setCronLastTime();

      // Release cron lock.
      $this->lock->release('cron');

      // Return TRUE so other functions can check if it did run successfully
      $return = TRUE;
    }

    // Process cron queues.
    $this->processQueues();

    // Restore the user.
    $this->accountSwitcher->switchBack();

    return $return;
  }

  /**
   * Records and logs the request time for this cron invocation.
   */
  protected function setCronLastTime() {
    // Record cron time.
    $this->state->set('system.cron_last', REQUEST_TIME);
    $this->logger->notice('Cron run completed.');
  }

  /**
   * Processes cron queues.
   */
  protected function processQueues() {
    // Grab the defined cron queues.
    foreach ($this->queueManager->getDefinitions() as $queue_name => $info) {
      if (isset($info['cron'])) {
        // Make sure every queue exists. There is no harm in trying to recreate
        // an existing queue.
        $this->queueFactory->get($queue_name)->createQueue();

        $queue_worker = $this->queueManager->createInstance($queue_name);
        $end = time() + (isset($info['cron']['time']) ? $info['cron']['time'] : 15);
        $queue = $this->queueFactory->get($queue_name);
        while (time() < $end && ($item = $queue->claimItem())) {
          try {
            $queue_worker->processItem($item->data);
            $queue->deleteItem($item);
          }
          catch (RequeueException $e) {
            // The worker requested the task be immediately requeued.
            $queue->releaseItem($item);
          }
          catch (SuspendQueueException $e) {
            // If the worker indicates there is a problem with the whole queue,
            // release the item and skip to the next queue.
            $queue->releaseItem($item);

            watchdog_exception('cron', $e);

            // Skip to the next queue.
            continue 2;
          }
          catch (\Exception $e) {
            // In case of any other kind of exception, log it and leave the item
            // in the queue to be processed again later.
            watchdog_exception('cron', $e);
          }
        }
      }
    }
  }

  /**
   * Invokes any cron handlers implementing hook_cron.
   */
  protected function invokeCronHandlers() {
    $module_previous = '';

    // Iterate through the modules calling their cron handlers (if any):
    foreach ($this->moduleHandler->getImplementations('cron') as $module) {

      if (!$module_previous) {
        $this->logger->notice('Starting execution of @module_cron().', [
          '@module' => $module,
        ]);
      }
      else {
        $this->logger->notice('Starting execution of @module_cron(), execution of @module_previous_cron() took @time.', [
          '@module' => $module,
          '@module_previous' => $module_previous,
          '@time' => Timer::read('cron_' . $module_previous) . 'ms',
        ]);
      }
      Timer::start('cron_' . $module);

      // Do not let an exception thrown by one module disturb another.
      try {
        $this->moduleHandler->invoke($module, 'cron');
      }
      catch (\Exception $e) {
        watchdog_exception('cron', $e);
      }

      Timer::stop('cron_' . $module);
      $module_previous = $module;
    }
  }

}
