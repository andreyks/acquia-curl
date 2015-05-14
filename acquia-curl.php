#!/usr/bin/env php
<?php
/**
 * Acquia Cloud API v1
 * First usage "./acquia-curl.php reset" to create cache
 * To view usage run "./acquia-curl.php"
 */ 

// Parse args and init.
$script_name = array_shift($argv);
if($argc < 2) {
	echo "Usage: {$script_name} <command> domain1 domain2 ...\n";
	// TODO: list available commands
	exit(1);
}

$command = array_shift($argv);
$c = new AcquiaCurlCommand();
// TODO: check for options (args which started from -)
if (empty($argv)) {
	$c->execute($command);
}
else {
	foreach ($argv as $arg) {
		$c->execute($command, $arg);
	}
}




class AcquiaCurl {
	// Settings
	protected $endpoint = 'https://cloudapi.acquia.com/v1/';
	protected $username;
	protected $token;
	protected $c;
	protected $debug;

	function __construct() {
		$settings = parse_ini_file("acquia-curl.ini");
		$this->username = $settings['username'];
		$this->token = $settings['token'];
		$this->debug = $settings['debug'];

		$this->c = curl_init();
		curl_setopt($this->c, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->c, CURLOPT_USERPWD, "{$this->username}:{$this->token}");
	}

	public function fetch($command) {
		if ($this->debug) {
			print "Execute: {$this->endpoint}{$command}.json ...\n";
		}
		curl_setopt($this->c, CURLOPT_URL, "{$this->endpoint}{$command}.json");
	        $resp = curl_exec($this->c);
		if ($this->debug) {
			print $resp . "\n";
		}
		$result = json_decode($resp, TRUE);
		return $result;
	}
}

class AcquiaCurlCommand extends AcquiaCurl {
	protected $cache_file = 'acquia-curl.json';
	protected $cache = array();

	protected $commands = array(
		'reset' => 'Build domain cache. Run it in first and after domain add/delete',
		'view' => 'View current cache',
		'varnish' => 'Reset varnish for domains. Usage: ./acquia-curl.php varnish domain1 domain2 ...',
	);

	function __construct() {
		parent::__construct();
		if (is_readable($this->cache_file)) {
			$json = file_get_contents($this->cache_file);
			$this->cache = json_decode($json, TRUE);
		}
		else {
			print  "Cache file is not readable or does not exist\n";
		}
	}

	public function execute($command, $args = array()) {
		if (in_array($command, array_keys($this->commands))) {
			$this->$command($args);
		}
	}

	protected function reset($args = array()) {
		$sites = $this->fetch("sites");
		$cache = array(
			'sites' => array_fill_keys($sites, array()), // site > env > domain
			'domain' => array(), // domain > env, site
		);
		foreach($sites as $site) {
		        $envs = $this->fetch("sites/{$site}/envs");
		        foreach ($envs as $env) {
		               $env = $env['name'];
		               $cache['sites'][$site][$env] = array();
		               $domains = $this->fetch("sites/{$site}/envs/{$env}/domains");
		               foreach ($domains as $domain) {
		                      $domain = $domain['name'];
				      $cache['sites'][$site][$env][$domain] = $domain;
				      $cache['domains'][$domain] = array(
					      'alias' => '',
					      'env' => $env,
					      'site' => $site,
				      );
		               }
		       }
		}
		$fp = fopen($this->cache_file, 'w');
		fwrite($fp, json_encode($cache));
		fclose($fp);
		print  "Cache file updated\n";
	}

	protected function view($arg = '') {
		var_dump($this->cache);
	}

	protected function varnish($arg) {
		// curl -s -u "${username}:${token}" -X DELETE ${endpoint}sites/${site}/envs/${env}/domains/${domain}/cache.json
		$domain = $arg;
		print "Clear varnish for {$domain}\n";
		if (!isset($this->cache['domains'][$domain])) {
			print "Missing domain {$domain}\n";
			return;
		}

		$site = $this->cache['domains'][$domain]['site'];
		$env = $this->cache['domains'][$domain]['env'];
		curl_setopt($this->c, CURLOPT_CUSTOMREQUEST, "DELETE");
		$result = $this->fetch("sites/${site}/envs/${env}/domains/${domain}/cache");
	}
}

