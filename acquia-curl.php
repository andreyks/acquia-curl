#!/usr/bin/env php
<?php
/**
 * Acquia Cloud API v1
 * First usage "./acquia-curl.php reset" to create cache
 * To view usage run "./acquia-curl.php"
 */

// Init.
$c = new AcquiaCurlCommand();

// Validate argument count
$script_name = array_shift($argv);
if ($argc < 2) {
    echo "Usage: {$script_name} <command> domain1 domain2 ...\n";
    // List available commands.
    $usages = $c->getAllowedCommands();
    foreach ($usages as $usage) {
        printf($usage . "\n", $script_name);
    }
    exit(1);
}

// Parse args and options
$command = array_shift($argv);
$args = array();
$options = array();
foreach ($argv as $arg) {
    // If arg starts from "-" then it is option.
    if (strlen($arg) > 1 && strpos($arg, '-') === 0) {
        $options[] = $arg;
    }
    else {
        $args[] = $arg;
    }
}

// Execute command
if (empty($args)) {
    $c->execute($command, NULL, $options);
} else {
    foreach ($args as $arg) {
        $c->execute($command, $arg, $options);
    }
}

/**
 * Base class for Acquia cloud API.
 * Uses https://cloudapi.acquia.com/v1/.
 */
class AcquiaCurl {
    // Settings.
    protected $endpoint = 'https://cloudapi.acquia.com/v1/';
    protected $curl;
    protected $debug = 0;
    protected $settings = array();
    
    const NOT_AUTORIZED = 'Not authorized';

    function __construct() {
        // Read settings.
        $this->settings = parse_ini_file("acquia-curl.ini");
        $this->debug = $this->settings['debug'];
        $username = $this->settings['username'];
        $token = $this->settings['token'];

        // Init curl.
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_USERPWD, "{$username}:{$token}");
    }

    public function fetch($command) {
        if ($this->debug > 0) {
            print "Execute: {$this->endpoint}{$command}.json ...\n";
        }
        
        // Send request and fetch responce.
        curl_setopt($this->curl, CURLOPT_URL, "{$this->endpoint}{$command}.json");
        $resp = curl_exec($this->curl);
        $result = json_decode($resp, TRUE);
        
        // Validate result.
        if (isset($result['message']) && $result['message'] == self::NOT_AUTORIZED) {
            print "Error: " . self::NOT_AUTORIZED . ". Please check username and token\n";
        }
        
        if ($this->debug > 1) {
            print $resp . "\n";
        }
        
        return $result;
    }
    
    protected function _parse_domain($str) {
        // Strip https:// and "http://"
        if (strpos($str, '//') !== FALSE) {
            $str = parse_url($str, PHP_URL_HOST);
        } 
        else {
            $parts = explode("/", $str);
            $str = $parts[0];
        }
        return $str;
    }
}

/**
 * Implements https://cloudapi.acquia.com/
 */
class AcquiaCurlCommand extends AcquiaCurl {
    protected $cache = array();
    protected $allowed_commands = array(
        'reset' => 'Build domain cache. Run it in first and after domain add/delete. Usage: %s reset',
        'view' => 'View current cache. Usage: %s view',
        'varnish' => 'Reset varnish for domains. Usage: %s varnish domain1 domain2 ...',
	'ssh_connect' => 'Print ssh connect string for domains. Usage %s ssh-connect domain1 doamin2 ...',
    );
    protected $allowed_options = array(
        '-v' => 'Enable debug mode. Override value from config.',
        '-h' => 'Print default help',
    );

    function __construct() {
        parent::__construct();
        if (is_readable($this->settings['cache_file'])) {
            $json = file_get_contents($this->settings['cache_file']);
            $this->cache = json_decode($json, TRUE);
        } else {
            print "Cache file is not readable or does not exist\n";
        }
    }
    
    public function getAllowedCommands() {
        return $this->allowed_commands;
    }

    public function execute($command, $arg = '', $options = array()) {
        if (in_array($command, array_keys($this->allowed_commands))) {
            $this->$command($arg);
        }
    }

    protected function reset($args = array()) {
        $sites = $this->fetch("sites");
        $cache = array(
            'sites' => array_fill_keys($sites, array()), // site > env > domain
            'domain' => array(), // domain > env, site
            'drushrc' =>array(), 
        );
        
        // Fetch domain info
        foreach ($sites as $site) {
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
        
        // Fetch drushrc info (alias, remote-host, remote-user)
        // curl -s -u "${username}:${token}" ${endpoint}me/drushrc.json
        $drushrc = $this->fetch("me/drushrc");
        $cache['drushrc'] = $drushrc;
        
        // Save to cache
        $fp = fopen($this->settings['cache_file'], 'w');
        fwrite($fp, json_encode($cache));
        fclose($fp);
        print "Cache file updated\n";
    }

    protected function view($arg = '') {
        var_dump($this->cache);
    }

    protected function varnish($arg) {
        // curl -s -u "${username}:${token}" -X DELETE ${endpoint}sites/${site}/envs/${env}/domains/${domain}/cache.json
        $domain = $this->_parse_domain($arg);
        print "Clear varnish for {$domain}\n";
        if (empty($domain)) {
            return;
        }

        $site = $this->cache['domains'][$domain]['site'];
        $env = $this->cache['domains'][$domain]['env'];
        
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        $result = $this->fetch("sites/${site}/envs/${env}/domains/${domain}/cache");
    }

    protected function ssh_connect($arg) {
        $domain = $this->_parse_domain($arg);
        print "Ssh connect line:\n";
        if (empty($domain)) {
            return;
        }

        $site = $this->cache['domains'][$domain]['site'];
        $env = $this->cache['domains'][$domain]['env'];
        
        $drushrc = $this->cache['drushrc'][$site][$env];
        $drush_major_version = 7;  
        // TODO: validate server result!!
        eval($drushrc);
        
        printf("ssh -l%s %s\n", $aliases[$env]['remote-user'], $aliases[$env]['remote-host']);
    }

    protected function _parse_domain($str) {
        $domain = parent::_parse_domain($str);
        if (!isset($this->cache['domains'][$domain])) {
            print "Missing domain {$domain}\n";
            // Do not exit() here because command allows multiple args (domains).
            return NULL;
        }
        return $domain;
    }

}
