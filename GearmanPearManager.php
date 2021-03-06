<?php

/**
 * Implements the worker portions of the PEAR Net_Gearman library
 *
 * @author      Brian Moon <brian@moonspot.net>
 * @copyright   1997-Present Brian Moon
 * @package     GearmanManager
 *
 */

require __DIR__."/GearmanManager.php";

/**
 * Implements the worker portions of the PEAR Net_Gearman library
 */
class GearmanPearManager extends GearmanManager {

	public static $LOG = array();

	private $start_time;

	/**
	 * Starts a worker for the PEAR library
	 *
	 * @param   array   $worker_list    List of worker functions to add
	 * @return  void
	 *
	 */
	protected function start_lib_worker($worker_list) {

		/**
		 * Require PEAR Net_Gearman libs
		 */
		if (defined('NET_GEARMAN_JOB_PATH')) {
			$this->log(
				"Taking worker dir from pre-exising constant, not config file.",
				self::LOG_LEVEL_DEBUG
			);
		}
		else {
			define('NET_GEARMAN_JOB_PATH', $this->worker_dir);
		}

		if(!class_exists("Net_Gearman_Job_Common")) {
			require "Net/Gearman/Job/Common.php";
		}

		if(!class_exists("Net_Gearman_Job")) {
			require "Net/Gearman/Job.php";
		}

		if(!class_exists("Net_Gearman_Worker")) {
			require "Net/Gearman/Worker.php";
		}

		$worker = new Net_Gearman_Worker($this->servers);

		foreach($worker_list as $w) {
			$this->log("Adding job $w", self::LOG_LEVEL_WORKER_INFO);
			$worker->addAbility($w);
		}

		$worker->attachCallback(array($this, 'job_start'), Net_Gearman_Worker::JOB_START);
		$worker->attachCallback(array($this, 'job_complete'), Net_Gearman_Worker::JOB_COMPLETE);
		$worker->attachCallback(array($this, 'job_fail'), Net_Gearman_Worker::JOB_FAIL);

		$this->start_time = time();

		$worker->beginWork(array($this, "monitor"));
	}

	/**
	 * Monitor call back for worker. Return false to stop worker
	 *
	 * @param   bool    $idle       If true the worker was idle
	 * @param   int     $lastJob    The time the last job was run
	 * @return  bool
	 *
	 */
	public function monitor($idle, $lastJob) {

		if($this->max_run_time > 0 && time() - $this->start_time > $this->max_run_time) {
			$this->log("Been running too long, exiting", self::LOG_LEVEL_WORKER_INFO);
			$this->stop_work = true;
		}

		$time = time() - $lastJob;

		$this->log("Worker's last job $time seconds ago", self::LOG_LEVEL_CRAZY);

		return $this->stop_work;
	}

	/**
	 * Call back for when jobs are started
	 */
	public function job_start($handle, $job, $args) {
		$this->log("($handle) Starting Job: $job", self::LOG_LEVEL_WORKER_INFO);
		$this->log("($handle) Workload: ".json_encode($args), self::LOG_LEVEL_DEBUG);
		self::$LOG = array();
	}

	/**
	 * Call back for when jobs are completed
	 */
	public function job_complete($handle, $job, $result) {
		$this->log("($handle) Completed Job: $job", self::LOG_LEVEL_WORKER_INFO);

		$this->log_result($handle, $result);
	}

	/**
	 * Call back for when jobs fail
	 */
	public function job_fail($handle, $job, $result) {
		$message = "($handle) Failed Job: $job: ".$result->getMessage();

		$this->log($message, self::LOG_LEVEL_WORKER_INFO);

		$this->log_result($handle, $result);
	}

	/**
	 * Logs the result of complete/failed jobs
	 *
	 * @param   mixed   $result     Result returned from worker
	 * @return  void
	 *
	 */
	private function log_result($handle, $result) {

		if(!empty(self::$LOG)) {
			foreach(self::$LOG as $l) {
				if(!is_scalar($l)) {
					$l = explode("\n", trim(print_r($l, true)));
				} elseif(strlen($l) > 256) {
					$l = substr($l, 0, 256)."...(truncated)";
				}

				if(is_array($l)) {
					$log_message = "";
					foreach($l as $ln) {
						$log_message.= "($handle) $ln\n";
					}
					$this->log($log_message, self::LOG_LEVEL_WORKER_INFO);
				} else {
					$this->log("($handle) $l", self::LOG_LEVEL_WORKER_INFO);
				}
			}
		}

		$result_log = $result;

		if(!is_scalar($result_log)) {
			$result_log = explode("\n", trim(print_r($result_log, true)));
		} elseif(strlen($result_log) > 256) {
			$result_log = substr($result_log, 0, 256)."...(truncated)";
		}

		if(is_array($result_log)) {
			$log_message = "";
			foreach($result_log as $ln) {
				$log_message.="($handle) $ln\n";
			}
			$this->log($log_message, self::LOG_LEVEL_DEBUG);
		} else {
			$this->log("($handle) $result_log", self::LOG_LEVEL_DEBUG);
		}
	}

	/**
	 * Validates the PECL compatible worker files/functions
	 */
	protected function validate_lib_workers() {

		/**
		 * Yes, we include these twice because this function is called
		 * by a different process than the other location where these
		 * are included.
		 */
		if(!class_exists("Net_Gearman_Job_Common")) {
			require "Net/Gearman/Job/Common.php";
		}

		if(!class_exists("Net_Gearman_Job")) {
			require "Net/Gearman/Job.php";
		}

		/**
		 * Validate functions
		 */
		foreach($this->functions as $name => $func) {
			$class = NET_GEARMAN_JOB_CLASS_PREFIX.$name;
			include $func['path'];
			if(!class_exists($class) && !method_exists($class, "run")) {
				$this->log("Class $class not found in {$func['path']} or run method not present");
				posix_kill($this->pid, SIGUSR2);
				exit();
			}
		}
	}
}
