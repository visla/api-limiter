<?php
/**
 * Dependencies:
 *   memcached
 */

namespace AxiomCoders;

/**
 * API Limiter
 */
class ApiLimiter
{
    const STATUS_OK = 1;
    const STATUS_LIMIT_REACHED = 0;
    const STATUS_FUNCTION_NOT_FOUND = 3;
    const STATUS_CLIENT_API_NOT_FOUND = 4;

    const KEY_PREFIX = "ApiLimiter_";

    /**
     * Instance.
     * @var ApiLimiter
     */
    public static $instance = null;

    /**
     * Options
     * Here is example how it looks like:
     *  [
     *    'functionName' : {
     *      allowedCalls : 100,
     *      timeframe : 1000, // in seconds,
     *      allowedIPSources : 0 // unlimited unique IP sources. If set to 5 then only 5 different IP addresses could use this function name.
     *      IPSourcesExpiration: 1000 // when are different IP Sources going to expire. Usually this is way more than timeframe.
     *    }
     *  ]
     * @var array Array of objects.
     */
    public $options = array();

    /**
     * Memcached object.
     * @var Memcached
     */
    protected $memCached;

    /**
     * Singleton
     * @return ApiLimiter
     */
    public static function getInstance()
    {
        if (self::$instance == null)
        {
            self::$instance = new ApiLimiter();
        }

        return self::$instance;
    }

    /**
     * Set instance. 
     * @param ApiLimter $instance
     */
    public static function setInstance($instance)
    {
        self::$instance = $instance;
    }

    /**
     * Constructor
     * @param object $memcached If null it will create one locally. If provided must have added servers already.
     */
    public function __construct($memcached = null)
    {
        if (!class_exists('\Memcached'))
        {
            throw new Exception("Class Memcached not found. Install memcached extension.");
        }

        if (!$memcached)
        {
             $this->memCached = new \Memcached();

             // Default is local server.
             $this->memCached->addServers(array(
                array('127.0.0.1', 11211, 100)));
        }
    }

    /**
     * Get memcached object. Used mostly for tests.
     * @return Memcached
     */
    public function getMemcached()
    {
        return $this->memCached;
    }

    /**
     * Set options.
     * @param type $options
     */
    public function setOptions($options)
    {
        $this->options = $options;
    }

    /**
     * Load options from JSON filename. It will override any loaded options so far.
     * @param string $filename
     *
     * @return bool FALSE if failed loading.
     */
    public function loadOptionsJSON($filename)
    {
        if (is_file($filename))
        {
            $content = file_get_contents($filename);
            $this->options = (array)json_decode($content);
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * Get Memcache key for function name.
     * @param string $functionName
     * @param string $variableId
     * @param string $fromIp
     *
     * @return string
     */
    public function getKey($functionName, $variableId, $fromIp)
    {
         return self::KEY_PREFIX . $fromIp . '_' . $functionName . '_'. $variableId;
    }

    /**
     * Get memcache key for IP source count.
     * @param string $functionName
     * @param string $variableId
     */
    public function getSourcesCountKey($functionName, $variableId = '')
    {
        return self::KEY_PREFIX . $functionName . '_' . $variableId;
    }

    /**
     * Check limit on call to a function. 
     * @param string $functionName
     * @param string $variableId Used to specify function call in more detailed way. For example if function limit
     *                           depends also on some parameter. Default is empty string.
     * @param string $fromIp If left null it will be taken from X-FORWARDED-FOR header or IP of client.
     *
     * @return int One of the values. STAUS_OK, STATUS_LIMIT_REACHED, STATUS_FUNCTION_NOT_FOUND
     */
    public function checkLimit($functionName, $variableId = '', $fromIp = null)
    {
        // check if we have options for this function name.
        if (!$functionName || !array_key_exists($functionName, $this->options))
        {
            return self::STATUS_FUNCTION_NOT_FOUND;
        }

        if (!$fromIp)
        {
           $fromIp = $this->getClientIP();
        }

        if ($fromIp === false)
        {
            return self::STATUS_CLIENT_IP_NOT_FOUND;
        }

        $functionOptions = $this->options[$functionName];

        // Check for unlimited cases.
        if ($functionOptions->allowedIPSources == 0 && ($functionOptions->allowedCalls == 0 || $functionOptions->timeframe == 0))
        {
            return self::STATUS_OK;
        }

        // We have two cases to handle. One is when we don't count number of allowedIpAddresses.
        // the other is when we care about allowedIPAdresses
        if ($functionOptions->allowedIPSources == 0)
        {
            // We provide counter for single IP Address for that function.
            $key = $this->getKey($functionName, $variableId, $fromIp);

            $count = $this->memCached->get($key);
            if ($count === false)
            {
                // Set new key
                $count = 1;
                $result = $this->memCached->set($key, $count, $functionOptions->timeframe);
                if ($result === false)
                {
                    throw new Exception("Error setting key value $key");
                }
            }
            else
            {
                // Check the limit and increase the count.
                if ($count >= $functionOptions->allowedCalls)
                {
                    return self::STATUS_LIMIT_REACHED;
                }
                else
                {
                    $result = $this->memCached->increment($key);
                    if ($result === false)
                    {
                        throw new Exception("Error incrementing key: $key");
                    }
                }
            }
        }
        else
        {
        // --- Check sources count ----
            // Here we are also checking the number of allowed IP Addresses.
            $key = $this->getKey($functionName, $variableId, $fromIp);
            $maxSourcesKey = $this->getSourcesCountKey($functionName, $variableId);

            // Check if we reached limit on IP Addresses.
            $callCount = $this->memCached->get($key);
            if ($callCount === false)
            {
                // Log the call.
                $this->memCached->set($key, 1, $functionOptions->timeframe);

                $countOfSources = $this->memCached->get($maxSourcesKey);
                if ($countOfSources === false)
                {
                    // Create max sources entry.
                    $result = $this->memCached->set($maxSourcesKey, 1, $functionOptions->IPSourcesExpiration);
                    if ($result === false)
                    {
                        throw new Exception("Error setting max sources key: $maxSourcesKey");
                    }
                }
                else
                {
                    if ($countOfSources >= $functionOptions->allowedIPSources)
                    {
                        return self::STATUS_LIMIT_REACHED;
                    }
                    else
                    {
                        $this->memCached->increment($maxSourcesKey);
                    }
                }
            }
            else
            {
                // We had calls to this function from this IP before.
                if ($functionOptions->allowedCalls > 0 && $callCount >= $functionOptions->allowedCalls)
                {
                    return self::STATUS_LIMIT_REACHED;
                }
                else
                {
                    $this->memCached->increment($key);
                    
                    // check the sources count.
                    $countOfSources = $this->memCached->get($maxSourcesKey);
                    if ($countOfSources === false)
                    {
                        // Create max sources entry.
                        $result = $this->memCached->set($maxSourcesKey, 1, $functionOptions->IPSourcesExpiration);
                        if ($result === false)
                        {
                            throw new Exception("Error setting max sources key: $maxSourcesKey");
                        }
                    }
                }
            }
        }

        return self::STATUS_OK;
    }

    /**
     * Get clients IP address.
     * 
     * @return string Returns IP address or FALSE on error.
     */
    protected function getClientIP()
    {
        // Get IP Address from proxy header first. then if nothing from client IP address.
        $header = $this->getHeader('X-FORWARDED-FOR');

        if ($header !== false)
        {
            // In case we have multiple proxyies ip addresses are separated by coma.
            $headers = explode(',', $header);
            $fromIp = $headers[0];
        }
        else
        {
            // Get IP from REMOTE_ADDR.
            $fromIp = $_SERVER['REMOTE_ADDR'];
        }

        if ($fromIp)
        {
            return $fromIp;
        }
        else
        {
            return false;
        }
    }

    /**
     * Get header from request.
     * @param string $header name of header. Example Content-Type
     * @return string Value of the header or FALSE if not found.
     */
    protected function getHeader($header)
    {
        if (!function_exists('getallheaders'))
        {
            // < PHP 5.4 - we need to manually get headers from SERVER variable.
            $headers = array();
            foreach ($_SERVER as $name => $value)
            {
                if (substr($name, 0, 5) == 'HTTP_')
                {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
        }
        else
        {
            // Post PHP 5.4 on FastCGI
            $headers = getallheaders();
        }

        // Check if header is set
        if(array_key_exists($header, $headers))
        {
            return $headers[$header];
        }
        else
        {
            return false;
        }
    }

}
