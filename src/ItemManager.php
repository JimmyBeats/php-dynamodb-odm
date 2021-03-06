<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-09-03
 * Time: 19:13
 */

namespace Oasis\Mlib\ODM\Dynamodb;

use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\FilesystemCache;
use Oasis\Mlib\ODM\Dynamodb\DBAL\Drivers\Connection;
use Oasis\Mlib\ODM\Dynamodb\DBAL\Drivers\DynamoDbConnection;
use Oasis\Mlib\ODM\Dynamodb\Exceptions\ODMException;
use Symfony\Component\Finder\Finder;

use function is_dir;
use function mwarning;

class ItemManager
{
    /**
     * @var string[]
     */
    protected $possibleItemClasses = [];
    
    protected $databaseConfig;
    protected $defaultTablePrefix;
    
    /** @var  AnnotationReader */
    protected $reader;
    /**
     * @var ItemReflection[]
     * Maps item class to item reflection
     */
    protected $itemReflections;
    /**
     * @var ItemRepository[]
     * Maps item class to corresponding repository
     */
    protected $repositories = [];
    
    /**
     * @var array
     */
    protected $reservedAttributeNames = [];
    
    /**
     * @var bool
     */
    protected $skipCheckAndSet = false;

    /**
     * @var Connection
     */
    protected $dbConnection = null;


    /**
     * ItemManager constructor.
     *
     * @param  array|Connection  $dbCnn
     * @param  string  $defaultTablePrefix
     * @param  string  $cacheDir
     * @param  bool  $isDev
     * @throws AnnotationException
     *
     * @noinspection PhpDeprecationInspection
     */
    public function __construct($dbCnn, $defaultTablePrefix, $cacheDir, $isDev = true)
    {
        if ($dbCnn instanceof Connection) {
            $this->dbConnection = $dbCnn;
        }
        else {
            $this->databaseConfig = $dbCnn;
        }

        $this->defaultTablePrefix = $defaultTablePrefix;
        
        AnnotationRegistry::registerLoader([$this, 'loadAnnotationClass']);
        
        $this->reader = new CachedReader(
            new AnnotationReader(),
            new FilesystemCache($cacheDir),
            $isDev
        );
    }
    
    public function addNamespace($namespace, $srcDir)
    {
        if (!is_dir($srcDir)) {
            mwarning("Directory %s doesn't exist.", $srcDir);
            
            return;
        }
        $finder = new Finder();
        $finder->in($srcDir)
               ->path('/\.php$/');
        foreach ($finder as $splFileInfo) {
            $classname = sprintf(
                "%s\\%s\\%s",
                $namespace,
                str_replace("/", "\\", $splFileInfo->getRelativePath()),
                $splFileInfo->getBasename(".php")
            );
            $classname = preg_replace('#\\\\+#', '\\', $classname);
            //mdebug("Class name is %s", $classname);
            $this->possibleItemClasses[] = $classname;
        }
    }
    
    public function addReservedAttributeNames(...$args)
    {
        foreach ($args as $arg) {
            $this->reservedAttributeNames[] = $arg;
        }
    }
    
    public function clear()
    {
        foreach ($this->repositories as $itemRepository) {
            $itemRepository->clear();
        }
    }
    
    public function detach($item)
    {
        if (!is_object($item)) {
            throw new ODMException("You can only detach a managed object!");
        }
        $this->getRepository(get_class($item))->detach($item);
    }
    
    public function flush()
    {
        foreach ($this->repositories as $repository) {
            $repository->flush();
        }
    }
    
    public function get($itemClass, array $keys, $consistentRead = false)
    {
        return $this->getRepository($itemClass)->get($keys, $consistentRead);
    }
    
    /**
     * @deprecated use shouldSkipCheckAndSet() instead
     * @return bool
     */
    public function isSkipCheckAndSet()
    {
        return $this->skipCheckAndSet;
    }
    
    /**
     * @param bool $skipCheckAndSet
     */
    public function setSkipCheckAndSet($skipCheckAndSet)
    {
        $this->skipCheckAndSet = $skipCheckAndSet;
    }
    
    /**
     * @param $className
     *
     * @internal
     * @return bool
     */
    public function loadAnnotationClass($className)
    {
        if (class_exists($className)) {
            return true;
        }
        else {
            return false;
        }
    }
    
    public function persist($item)
    {
        $this->getRepository(get_class($item))->persist($item);
    }
    
    public function refresh($item, $persistIfNotManaged = false)
    {
        $this->getRepository(get_class($item))->refresh($item, $persistIfNotManaged);
    }
    
    public function remove($item)
    {
        $this->getRepository(get_class($item))->remove($item);
    }
    
    /**
     * @return bool
     */
    public function shouldSkipCheckAndSet()
    {
        return $this->skipCheckAndSet;
    }
    
    /**
     * @return mixed
     */
    public function getDefaultTablePrefix()
    {
        return $this->defaultTablePrefix;
    }
    
    /**
     * @return array
     * todo: set deprecated and recommend getDatabaseConfig() instead
     */
    public function getDynamodbConfig()
    {
        return $this->getDatabaseConfig();
    }

    /**
     * @return array
     */
    public function getDatabaseConfig()
    {
        if ($this->dbConnection instanceof Connection) {
            return $this->dbConnection->getDatabaseConfig();
        }

        return $this->databaseConfig;
    }
    
    /**
     * @param $itemClass
     *
     * @return ItemReflection
     */
    public function getItemReflection($itemClass)
    {
        if (!isset($this->itemReflections[$itemClass])) {
            $reflection = new ItemReflection($itemClass, $this->reservedAttributeNames);
            $reflection->parse($this->reader);
            $this->itemReflections[$itemClass] = $reflection;
        }
        else {
            $reflection = $this->itemReflections[$itemClass];
        }
        
        return $reflection;
    }
    
    /**
     * @return string[]
     */
    public function getPossibleItemClasses()
    {
        return $this->possibleItemClasses;
    }
    
    /**
     * @return AnnotationReader
     */
    public function getReader()
    {
        return $this->reader;
    }
    
    /**
     * @param $itemClass
     *
     * @return ItemRepository
     */
    public function getRepository($itemClass)
    {
        if (!isset($this->repositories[$itemClass])) {
            $reflection                     = $this->getItemReflection($itemClass);
            $repoClass                      = $reflection->getRepositoryClass();
            $repo                           = new $repoClass(
                $reflection,
                $this
            );
            $this->repositories[$itemClass] = $repo;
        }
        else {
            $repo = $this->repositories[$itemClass];
        }
        
        return $repo;
    }
    
    /**
     * @return array
     */
    public function getReservedAttributeNames()
    {
        return $this->reservedAttributeNames;
    }
    
    /**
     * @param array $reservedAttributeNames
     */
    public function setReservedAttributeNames($reservedAttributeNames)
    {
        $this->reservedAttributeNames = $reservedAttributeNames;
    }

    /**
     *
     * Get a new database connection
     *
     * @return Connection
     */
    public function createDBConnection()
    {
        if ($this->dbConnection instanceof Connection) {
            return clone $this->dbConnection;
        }
        else {
            // default database connection
            return new DynamoDbConnection($this->getDatabaseConfig());
        }
    }

}
