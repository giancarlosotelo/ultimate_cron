<?php
/**
 * @file
 * Serial cron job launcher for Ultimate Cron.
 */

/**
 * Ultimate Cron launcher plugin class.
 */
class UltimateCronSerialLauncher extends UltimateCronLauncher {
  public $currentThread = NULL;

  /**
   * Default settings.
   */
  public function defaultSettings() {
    return array(
      'max_execution_time' => 3600,
      'max_threads' => 1,
      'thread' => 'any',
      'lock_timeout' => 3600,
    ) + parent::defaultSettings();
  }

  /**
   * Settings form for the crontab scheduler.
   */
  public function settingsForm(&$form, &$form_state, $job = NULL) {
    $elements = &$form['settings'][$this->type][$this->name];
    $values = &$form_state['values']['settings'][$this->type][$this->name];

    $elements['lock_timeout'] = array(
      '#title' => t("Job lock timeout"),
      '#type' => 'textfield',
      '#default_value' => $values['lock_timeout'],
      '#description' => t('Number of seconds to keep lock on job.'),
      '#fallback' => TRUE,
      '#required' => TRUE,
    );

    if (!$job) {
      $max_threads = $values['max_threads'];
      $elements['max_execution_time'] = array(
        '#title' => t("Maximum execution time"),
        '#type' => 'textfield',
        '#default_value' => $values['max_execution_time'],
        '#description' => t('Maximum execution time for a cron run in seconds.'),
        '#fallback' => TRUE,
        '#required' => TRUE,
      );
      $elements['max_threads'] = array(
        '#title' => t("Maximum number of launcher threads"),
        '#type' => 'textfield',
        '#default_value' => $max_threads,
        '#description' => t('The maximum number of launch threads that can be running at any given time.'),
        '#fallback' => TRUE,
        '#required' => TRUE,
        '#element_validate' => array('element_validate_number'),
      );
    }
    else {
      $settings = $this->getDefaultSettings();
      $max_threads = $settings['max_threads'];
    }

    $options = array('any' => t('-- Any-- '));
    for ($i = 1; $i <= $max_threads; $i++) {
      $options[$i] = $i;
    }
    $elements['thread'] = array(
      '#title' => t("Run in thread"),
      '#type' => 'select',
      '#default_value' => $values['thread'],
      '#options' => $options,
      '#description' => t('Which thread to run in when invoking with ?thread=N'),
      '#fallback' => TRUE,
      '#required' => TRUE,
    );
  }

  /**
   * Settings form validator.
   */
  public function settingsFormValidate(&$form, &$form_state, $job = NULL) {
    $elements = &$form['settings'][$this->type][$this->name];
    $values = &$form_state['values']['settings'][$this->type][$this->name];

    if (!$job) {
      if (intval($values['max_threads']) <= 0) {
        form_set_error("settings[$this->type][$this->name", t('%title must be greater than 0', array(
          '%title' => $elements['max_threads']['#title']
        )));
      }
    }
  }

  /**
   * Lock job.
   */
  public function lock($job) {
    $settings = $job->getSettings($this->type);
    $timeout = $settings['lock_timeout'];

    if ($lock_id = UltimateCronLock::lock($job->name, $timeout)) {
      $lock_id = $this->name . '-' . $lock_id;
      return $lock_id;
    }
    return FALSE;
  }

  /**
   * Unlock job.
   */
  public function unlock($lock_id, $manual = FALSE) {
    list($launcher, $lock_id) = explode('-', $lock_id, 2);
    return UltimateCronLock::unlock($lock_id);
  }

  /**
   * Check if job is locked.
   */
  public function isLocked($job) {
    $lock_id = UltimateCronLock::isLocked($job->name);
    return $lock_id ? $this->name . '-' . $lock_id : $lock_id;
  }

  /**
   * Check lock for multiple jobs.
   */
  public function isLockedMultiple($jobs) {
    $names = array();
    foreach ($jobs as $job) {
      $names[] = $job->name;
    }
    $lock_ids = UltimateCronLock::isLockedMultiple($names);
    foreach ($lock_ids as &$lock_id) {
      $lock_id = $lock_id ? $this->name . '-' . $lock_id : $lock_id;
    }
    return $lock_ids;
  }

  /**
   * Cleanup.
   */
  public function cleanup() {
    UltimateCronLock::cleanup();
  }

  /**
   * Launcher.
   */
  public function launch($job) {
    $lock_id = $job->lock();

    if (!$lock_id) {
      return FALSE;
    }

    if ($this->currentThread) {
      $init_message = t('Launched in thread @current_thread', array(
        '@current_thread' => $this->currentThread,
      ));
    }
    else {
      $init_message = t('Launched manually');
    }
    $log_entry = $job->startLog($lock_id, $init_message);

    drupal_set_message(t('@name: @init_message', array(
      '@name' => $job->name,
      '@init_message' => $init_message,
    )));

    // Run job.
    try {
      $job->run();
    }
    catch (Exception $e) {
      watchdog('serial_launcher', 'Error executing %job: @error', array('%job' => $job->name, '@error' => $e->getMessage()), WATCHDOG_ERROR);
      $log_entry->finish();
      $job->unlock($lock_id);
      return FALSE;
    }

    $log_entry->finish();
    $job->unlock($lock_id);
    return TRUE;
  }

  /**
   * Find a free thread for running cron jobs.
   */
  public function findFreeThread($lock, $lock_timeout = NULL, $timeout = 3) {
    $settings = $this->getDefaultSettings();

    // Find a free thread, try for 3 seconds.
    $delay = $timeout * 1000000;
    $sleep = 25000;

    error_log(print_r($settings, TRUE));
    do {
      for ($thread = 1; $thread <= $settings['max_threads']; $thread++) {
        if ($thread == $this->currentThread) {
          continue;
        }

        $lock_name = 'ultimate_cron_serial_launcher_' . $thread;
        if (!UltimateCronLock::isLocked($lock_name)) {
          if ($lock) {
            if ($lock_id = UltimateCronLock::lock($lock_name, $lock_timeout)) {
              return array($thread, $lock_id);
            }
          }
          else {
            return array($thread, FALSE);
          }
        }
        if ($delay > 0) {
          error_log("Sleeping: $sleep");
          usleep($sleep);
          // After each sleep, increase the value of $sleep until it reaches
          // 500ms, to reduce the potential for a lock stampede.
          $delay = $delay - $sleep;
          $sleep = min(500000, $sleep + 25000, $delay);
        }
      }
      error_log("Delay: $delay");
    } while (FALSE && $delay > 0);
    return array(FALSE, FALSE);
  }

  /**
   * Launch manager.
   */
  public function launchJobs($jobs) {
    #error_log('Serial launcher');
    #error_log(print_r(array_keys($jobs), TRUE));
    #return;

    $settings = $this->getDefaultSettings();

    // Set proper max execution time.
    $max_execution_time = ini_get('max_execution_time');
    $lock_timeout = max($max_execution_time, $settings['max_execution_time']);

    // If infinite max execution, then we use a day for the lock.
    $lock_timeout = $lock_timeout ? $lock_timeout : 86400;
    $lock_timeout = 60;

    if (!empty($_GET['thread'])) {
      $thread = intval($_GET['thread']);
      if ($thread < 1 || $thread > $settings['max_threads']) {
        watchdog('serial_launcher', "Invalid thread available for starting launch thread", array(), WATCHDOG_WARNING);
        return;
      }

      $timeout = 3;
      error_log("Locating free thread");
      list($thread, $lock_id) = $this->findFreeThread(TRUE, $lock_timeout, $timeout);
      error_log("Found thread: $thread");
    }
    else {
      $timeout = 3;
      error_log("Locating free thread");
      list($thread, $lock_id) = $this->findFreeThread(TRUE, $lock_timeout, $timeout);
      error_log("Found thread: $thread");
    }
    $this->currentThread = $thread;

    if (!$thread) {
      watchdog('serial_launcher', "No free threads available for launching jobs", array(), WATCHDOG_WARNING);
      return;
    }

    if ($max_execution_time && $max_execution_time < $settings['max_execution_time']) {
      set_time_limit($settings['max_execution_time']);
    }

    watchdog('serial_launcher', "Cron thread %thread started", array('%thread' => $thread), WATCHDOG_INFO);

    $this->runThread($lock_id, $thread, $jobs);
    UltimateCronLock::unlock($lock_id);
  }

  public function runThread($lock_id, $thread, $jobs) {
    $lock_name = 'ultimate_cron_serial_launcher_' . $thread;
    foreach ($jobs as $job) {
      $settings = $job->getSettings('launcher');
      $proper_thread = ($settings['thread'] == 'any') || ($settings['thread'] == $thread);
      if ($proper_thread && $job->isScheduled()) {
        error_log("Serial running: $job->name");
        $job->launch();
        // Be friendly, and check if we still own the lock.
        // If we don't, bail out, since someone else is handling
        // this thread.
        if ($lock_id !== UltimateCronLock::isLocked($lock_name)) {
          return;
        }
      }
    }
  }

  /**
   * Poormans cron launcher.
   */
  public function launchPoorman() {
    // Is it time to run cron?
    $cron_last = variable_get('cron_last', 0);
    $cron_next = floor(($cron_last + 60) / 60) * 60;
    $time = time();
    if ($time < $cron_next) {
      return;
    }
    error_log("Launching!");
    unset($_GET['thread']);
    ultimate_cron_poorman_page_flush();
    ultimate_cron_run_launchers();
  }
}
