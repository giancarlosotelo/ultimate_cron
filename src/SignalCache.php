<?php
/**
 * @file
 * Contains \Drupal\ultimate_cron\SignalCache.
 */


namespace Drupal\ultimate_cron;

class SignalCache {
  /**
   * Get a signal without claiming it.
   *
   * @param string $name
   *   The name of the job.
   * @param string $signal
   *   The name of the signal.
   *
   * @return string
   *   The signal if any.
   */
  static public function peek($name, $signal) {
    //$bin = variable_get('ultimate_cron_signal_cache_bin', 'signal');
    $bin = 'signal';
    //$cache = cache_get("signal-$name-$signal", $bin);
    $cache = \Drupal::cache($bin)->get("signal-$name-$signal");
    if ($cache) {
      $flushed = \Drupal::cache($bin)->get("flushed-$name");
      if (!$flushed || $cache->created > $flushed->created) {
        return $cache->data;
      }
    }
    return FALSE;
  }

  /**
   * Get and claim signal.
   *
   * @param string $name
   *   The name of the job.
   * @param string $signal
   *   The name of the signal.
   *
   * @return string
   *   The signal if any. If a signal is found, it is "claimed" and therefore
   *   cannot be claimed again.
   */
  static public function get($name, $signal) {
    if (\Drupal::lock()->acquire("signal-$name-$signal")) {
      $result = self::peek($name, $signal);
      self::clear($name, $signal);
      \Drupal::lock()->release("signal-$name-$signal");
      return $result;
    }
    return FALSE;
  }

  /**
   * Set signal.
   *
   * @param string $name
   *   The name of the job.
   * @param string $signal
   *   The name of the signal.
   *
   * @return boolean
   *   TRUE if the signal was set.
   */
  static public function set($name, $signal) {
    //$bin = variable_get('ultimate_cron_signal_cache_bin', 'signal');

    $bin = 'signal';
    \Drupal::cache($bin)->set("signal-$name-$signal", TRUE);
  }

  /**
   * Clear signal.
   *
   * @param string $name
   *   The name of the job.
   * @param string $signal
   *   The name of the signal.
   */
  static public function clear($name, $signal) {
//    $bin = variable_get('ultimate_cron_signal_cache_bin', 'signal');

    $bin = 'signal';
    \Drupal::cache($bin)->delete("signal-$name-$signal");
  }

  /**
   * Clear signals.
   *
   * @param string $name
   *   The name of the job.
   */
  static public function flush($name) {
//    $bin = variable_get('ultimate_cron_signal_cache_bin', 'signal');

    $bin = 'signal';
    \Drupal::cache($bin)->set("flushed-$name", microtime(TRUE));
  }
}
