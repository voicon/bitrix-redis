<?
/**
 * Created by PhpStorm.
 * User: dh
 * Date: 28.12.16
 * Time: 14:57
 */

namespace DHCache;

use Bitrix\Main\Config;

class CacheEngineRedis implements \Bitrix\Main\Data\ICacheEngine, \Bitrix\Main\Data\ICacheEngineStat
{
    /*
     * @var obMemcached - connection to memcached.
     */
    private static $obRedis = null;

    /*
     * @var isConnected -  is already connected
     */
    private static $isConnected = false;

    /*
     * @var sid - for using with several websites
     */
    private $sid = "BX";
    /*
     * @var read - bytes read
     */

    private $read = false;

    /*
     * @var written - bytes written
    */

    private $written = false;

    /*
     * @var baseDirVersion - array of base_dir
     */
    private static $baseDirVersion = array();

    /*
     * @var key - stored key
     */

    private $key='';

    /*
     * Constructor
     */

    function __construct()
    {
        $cacheConfig = Config\Configuration::getValue("cache");

        if (self::$obRedis == null)
        {
            self::$obRedis = new \Redis();

            if (isset($cacheConfig["hosts"]))
            {
                foreach ($cacheConfig["hosts"] as $host)
                {
                    if(empty($host[0]))
                    {
                        $host[0]="127.0.0.1";
                    }
                    if(empty($host[1]))
                    {
                        $host[0]="6379";
                    }
                    self::$isConnected = self::$obRedis->connect($host[0],$host[1]);
                }

            }
            else
            {
                self::$isConnected = self::$obRedis->connect("127.0.0.1");
            }

            if($cacheConfig["auth"])
            {
                self::$isConnected = self::$obRedis->auth($cacheConfig["auth"]);
            }
        }

        if ($cacheConfig && is_array($cacheConfig))
        {
            if (!empty($cacheConfig["sid"]))
            {
                $this->sid = $cacheConfig["sid"];
            }
        }
    }

    /*
     * Close connection
     *
     * @return void
     */

    function close()
    {
        if (self::$obRedis != null)
        {
            self::$obRedis->close();
        }
    }

    /*
     * Returns number of bytes read from memcached or false if there were no read operation
     *
     * @return integer|false
     */

    public function getReadBytes()
    {
        return $this->read;
    }

    /*
     * Returns number of bytes written to memcached or false if there were no write operation
     *
     * @return integer|false
     */
    public function getWrittenBytes()
    {
        return $this->written;
    }

    /*
     * Always return ""
     *
     * @return ""
     *
     */
    public function getCachePath()
    {
        //return $this->key;
        return "";
    }

    /*
     * Returns true if there's connection to memcached
     *
     * @return boolean
     */
    public function isAvailable()
    {
        return self::$isConnected;
    }

    /**
     * Cleans (removes) cache directory or file.
     *
     * @param string $baseDir Base cache directory.
     * @param string $initDir Directory within base.
     * @param string $filename File name.
     *
     * @return void
     */
    function clean($baseDir, $initDir = false, $filename = false)
    {
        if (is_object(self::$obRedis))
        {
            if (strlen($filename))
            {
                if (!isset(self::$baseDirVersion[$baseDir]))
                {
                    self::$baseDirVersion[$baseDir] = self::$obRedis->get($this->sid . $baseDir);
                }

                if (self::$baseDirVersion[$baseDir] === false || self::$baseDirVersion[$baseDir] === '')
                {
                    return;
                }

                if ($initDir !== false)
                {
                    $initDirVersion = self::$obRedis->get(self::$baseDirVersion[$baseDir] . "|" . $initDir);
                    if ($initDirVersion === false || $initDirVersion === '')
                    {
                        return;
                    }
                }
                else
                {
                    $initDirVersion = "";
                }

                self::$obRedis->del(self::$baseDirVersion[$baseDir] . "|" . $initDirVersion . "|" . $filename);
            }
            else
            {
                if (strlen($initDir))
                {
                    if (!isset(self::$baseDirVersion[$baseDir]))
                    {
                        self::$baseDirVersion[$baseDir] = self::$obRedis->get($this->sid . $baseDir);
                    }

                    if (self::$baseDirVersion[$baseDir] === false || self::$baseDirVersion[$baseDir] === '')
                    {
                        return;
                    }


                    self::$obRedis->del(self::$baseDirVersion[$baseDir] . "|" . $initDir);
                }
                else
                {
                    if (isset(self::$baseDirVersion[$baseDir]))
                    {
                        unset(self::$baseDirVersion[$baseDir]);
                    }

                    self::$obRedis->del($this->sid . $baseDir);
                }
            }
        }
    }

    /**
     * Reads cache from the memcached. Returns true if key value exists, not expired, and successfully read.
     *
     * @param mixed &$arAllVars Cached result.
     * @param string $baseDir Base cache directory.
     * @param string $initDir Directory within base.
     * @param string $filename File name.
     * @param integer $TTL Expiration period in seconds.
     *
     * @return boolean
     */
    function read(&$arAllVars, $baseDir, $initDir, $filename, $TTL)
    {
        if (!isset(self::$baseDirVersion[$baseDir]))
        {
            self::$baseDirVersion[$baseDir] = self::$obRedis->get($this->sid . $baseDir);
        }

        if (self::$baseDirVersion[$baseDir] === false || self::$baseDirVersion[$baseDir] === '')
        {
            return false;
        }

        if ($initDir !== false)
        {
            $initDirVersion = self::$obRedis->get(self::$baseDirVersion[$baseDir] . "|" . $initDir);
            if ($initDirVersion === false || $initDirVersion === '')
            {
                return false;
            }
        }
        else
        {
            $initDirVersion = "";
        }

        $this->key = self::$baseDirVersion[$baseDir] . "|" . $initDirVersion . "|" . $filename;

        $arAllVars_ser = self::$obRedis->get($this->key);


        $this->read=strlen($arAllVars_ser);

        $arAllVars = unserialize($arAllVars_ser);

        if ($arAllVars === false || $arAllVars === '')
        {
            return false;
        }

        return true;
    }

    /**
     * Puts cache into the memcached.
     *
     * @param mixed $arAllVars Cached result.
     * @param string $baseDir Base cache directory.
     * @param string $initDir Directory within base.
     * @param string $filename File name.
     * @param integer $TTL Expiration period in seconds.
     *
     * @return void
     */
    function write($arAllVars, $baseDir, $initDir, $filename, $TTL)
    {
        if (!isset(self::$baseDirVersion[$baseDir]))
            self::$baseDirVersion[$baseDir] = self::$obRedis->get($this->sid.$baseDir);

        if (self::$baseDirVersion[$baseDir] === false || self::$baseDirVersion[$baseDir] === '')
        {
            self::$baseDirVersion[$baseDir] = $this->sid.md5(mt_rand());
            self::$obRedis->set($this->sid.$baseDir, self::$baseDirVersion[$baseDir]);
        }

        if ($initDir !== false)
        {
            $initDirVersion = self::$obRedis->get(self::$baseDirVersion[$baseDir]."|".$initDir);
            if ($initDirVersion === false || $initDirVersion === '')
            {
                $initDirVersion = $this->sid.md5(mt_rand());
                self::$obRedis->set(self::$baseDirVersion[$baseDir]."|".$initDir, $initDirVersion);
            }
        }
        else
        {
            $initDirVersion = "";
        }

        $this->key = self::$baseDirVersion[$baseDir]."|".$initDirVersion."|".$filename;

        $arAllVars_ser=serialize($arAllVars);

        $this->written=strlen($arAllVars_ser);
        self::$obRedis->set($this->key, $arAllVars_ser, $TTL);
    }

    /**
     * Returns true if cache has been expired.
     * Stub function always returns true.
     *
     * @param string $path Absolute physical path.
     *
     * @return boolean
     */
    function isCacheExpired($path)
    {
        return false;
    }

}