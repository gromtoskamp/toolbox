<?php

require_once 'lib/SqlFormatter.php';

/**
 * Class Toolbox
 *
 * Toolbox for Magento-specific debugging.
 * Simply put, this is a static class filled with common function used in Magento development/debuggin by me.
 *
 * This is used to make sure I have to type as little as possible, because I am lazy.
 *
 * Advice: do not use unless you know what this does.
 *
 * @author: Tom Groskamp
 * @version: 0.0.2
 */

class T {

    /**
     * Possibilities for first param of self::performanceCheck($a, $b)
     */
    const PERFSTART = 'start';
    const PERFSTOP  = 'stop';
    const PERFMID   = 'mid';

    public static $test;

    const TEMPLATE_PATH = __DIR__ . '/design';

    /**
     * Toolbox constructor.
     *
     * Initializes Mage, since this is required for some of the included functions.
     */
    public function __construct() {
        Mage::app();
    }

    /**
     * Shorthand function to write a quick log to $logFile.
     *
     * @param $log
     * @param string $logFile
     */
    public static function log($log, $logFile = 'toolbox.log')
    {
        Mage::log($log, null, $logFile, true);
    }

    /**
     * Uses the self::debug() function to debug an object, and exits afterwards.
     *
     * @param $object
     * @param array $options
     */
    public static function dexit($object, $options = array()) {
        self::debug($object, $options);
        exit;
    }

    /**
     * Prints the class of provided object in bold format.
     * @param $object
     */
    public static function printClass($object, $options)
    {
        if (isset($options['console'])) {
            self::printToConsole(get_class($object));
            return;
        }

        echo '<h3 style="font-weight:bold;">';
        echo get_class($object);
        echo '</h3>';
    }

    /**
     * Handles collection printing, recursively.
     *
     * Has a hard limit of 50 items, to prevent out-of-memory problems while printing.
     *
     * @param Varien_Data_Collection $collection
     * @param array $options
     */
    public static function printCollection(Varien_Data_Collection $collection, $options = array())
    {
        self::printClass($collection, $options);
        $collectionSize = $collection->getSize();

        $collectionMetaData = array();
        $collectionMetaData['size'] = $collectionSize;

        try {
            $collectionMetaData['sqlString'] = self::getSql($collection, $options);
        } catch (Exception $e) {
            $collectionMetaData['sqlString'] = 'A problem has been encoutered printing the SQL!';
        }

        if ($collectionSize > 50) {
            $collection->setPageSize(50);
        }

        self::printt($collectionMetaData, (array_merge($options, array('collectionMetaData' => ''))));

        foreach ($collection as $collectionItem) {
            self::debug($collectionItem, $options);
        }

        return;
    }

    /**
     * Debugs an object, array or string using the options provided in $options.
     *
     * @param $object
     * @param array $options
     */
    public static function debug($object, $options = array())
    {
        $options = self::parseOptions($options);

        /**
         * Handle collections by debugging the individual collection items, rather than the collection.
         */
        if ($object instanceof Varien_Data_Collection) {
            self::printCollection($object, $options);
            return;
        }

        /**
         * If it is an object, also print the class of the object.
         */
        if (is_object($object)) {
            self::printClass($object, $options);
        }

        /**
         * If it is possible to call ->debug() on the object, then please do.
         */
        if (is_object($object) && method_exists($object, 'debug')) {
            $object = ($object->debug());
        }

        /**
         * Otherwise, just print it.
         */
        self::printt($object, $options);
    }

    /**
     * @param array $options
     * @return array|void
     */
    public static function parseOptions($options = array())
    {
        if (!is_array($options)) {
            $options = array($options);
        }

        if (isset($options['parsed'])) {
            return $options;
        }

        $options[] = 'parsed';

        return array_flip($options);
    }

    /**
     * @param $object
     * @param array $options
     */
    public static function printt($object, $options = array())
    {
        if (isset($options['console'])) {
            self::printToConsole($object);
            return;
        }

        if (isset($options['collectionMetaData'])) {
            if (isset($object['size'])) {
                echo '<h2>';
                echo 'Collection size: ' . $object['size'];
                echo '</h2>';
            }

            if (isset($object['sqlString'])) {
                echo '<h3>';
                echo 'Sql String: ' . $object['sqlString'];
                echo '</h3>';
            }

            return;
        }

        /**
         * Either var_dump this or just print_r it, depending on flag.
         */
        echo '<pre>';
        if (isset($options['vardump'])) {
            var_dump($object);
            echo '</pre>';
            return;
        }

        print_r($object);
        echo '</pre>';
    }

    /**
     * Easy method to get a product and load by either ID or other value.
     *
     * Standard input is product Id or Sku. First, product ID will be checked.
     * If this yields no result, try the sku instead.
     *
     * @param null $id
     * @param null $type
     * @return Mage_Catalog_Model_Product
     */
    public static function getProduct($id = null, $type = null)
    {
        /** @var Mage_Catalog_Model_Product $model */
        $model = Mage::getModel('catalog/product');

        if ($id) {

            if ($type) {
                $model->load($id, $type);
            }

            if (!$model->getId()) {
                $model->load($id);
            }
        }
        return $model;
    }

    /**
     * Easy method to get a order and load by either ID or other value.
     *
     * @param null $id
     * @param null $type
     * @return Mage_Sales_Model_Order
     */
    public static function getOrder($id = null, $type = null)
    {
        $model = Mage::getModel('sales/order');

        if ($id) {
            if ($type) {
                return $model->load($id, $type);
            }
            return $model->load($id);
        }
        return $model;
    }

    /**
     * Is callable with 'start' and 'stop', and a possible unique $id to have several performance checks at once.
     *
     * @param String $startStop
     * @param int $id
     *
     * @return void|float
     */
    public static function performanceCheck($startStop, $id = 1)
    {
        /**
         * Either set the current time when $startStop == 'start' and save it in the registry,
         * or get the start time from registry and calculate difference between then and now.
         *
         * If neither of these parameters is given, throw an exception to tell the user to be less silly.
         */
        try {
            $registryKey = self::getRegistryKey($id);
            if ($startStop == self::PERFSTART) {
                $time = microtime(true);
                Mage::register($registryKey, $time);

                return;
            }

            if (in_array($startStop, array(self::PERFSTOP, self::PERFMID))) {
                $time = microtime(true);
                $timeStart = Mage::registry(self::getRegistryKey($id));
                if($startStop == self::PERFSTOP) {
                    Mage::unregister(self::getRegistryKey($id));
                }
                $timeTaken = $time - $timeStart;

                return $timeTaken;
            }

            throw new Exception(sprintf("you should use '%s' or '%s' as first argument", self::PERFSTART, self::PERFSTOP));
        } catch (Exception $e) {
            self::printt($e->getMessage());
            self::printt($e->getTraceAsString());
            exit;
        }
    }

    /**
     * Gets registry key based on function from which getRegistryKey is called and possible extra unique $id.
     *
     * @param null $id
     * @return string
     * @throws Exception
     */
    public static function getRegistryKey($id = null)
    {
        $previousFunctionName = self::getPreviousFunction(1, false);
        $registryKey = $previousFunctionName . $id;

        return $registryKey;
    }

    /**
     * Get previous function called and return the function name.
     *
     * @param int $stepsBack
     * @param bool $returnPath
     * @return String
     * @throws Exception
     */
    public static function getPreviousFunction($stepsBack = 2, $returnPath = true)
    {
        $backTrace = debug_backtrace();
        if (!isset($backTrace[$stepsBack])) {
            //I just cant even.
            throw new Exception ('no previous function defined');
        }

        $previousFunction = $backTrace[$stepsBack]['function'];

        if ($returnPath) {
            $previousFunction .= ' - called in ' . $backTrace[$stepsBack-1]['file'] . ':' . $backTrace[$stepsBack-1]['line'];
        }

        return $previousFunction;
    }

    /**
     * Prints the previous function in the debug backtrace tree.
     * If $search is provided, will only print and die when $search is found in any of the filenames
     * If $ignoreTemplates == true, will ignore references to template files.
     *
     * @param null $search
     * @param bool $ignoreTemplates
     *
     * @throws Exception
     */
    public static function printPreviousFunction($search = null, $ignoreTemplates = true)
    {
        if (!$search) {
            echo '<pre>';
            print_r(array(
                T::getPreviousFunction(3)
            ));
            exit;
        }

        foreach (debug_backtrace() as $backTrace) {
            if (stripos($backTrace['file'], $search) !== false) {
                if ($ignoreTemplates && strpos($backTrace['file'], self::TEMPLATE_PATH) === 0) {
                    continue;
                }

                echo '<pre>';
                print_r(array(
                    T::getPreviousFunction(3)
                ));
                exit;

            }
        }
    }

    public static function printBackTrace()
    {
        echo '<table>';
        echo '<th style="font-weight: bold; padding: 2px;">File</th>';
        echo '<th style="font-weight: bold" padding: 2px;>Function</th>';
        echo '<th style="font-weight: bold" padding: 2px;>Line</th>';

        foreach (debug_backtrace() as $backTrace) {
            $fileName = str_replace(__DIR__ . '/', '', $backTrace['file']);
            echo '<tr>';
            echo '<td style="padding: 2px;">' . $fileName . '</td>';
            echo '<td style="padding: 2px;">' . $backTrace['function'] . '</td>';
            echo '<td style="padding: 2px;">' . $backTrace['line'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        exit;
    }


    /**
     * Get random object of the provided type. Mainly used for testing purposes.
     *
     * @param $type
     * @param array $options
     * @return mixed
     */
    public static function getRandomModel($type, $options = array())
    {
        $objectArray = self::getRandomCollection($type, 1, $options);

        return $objectArray[0];
    }

    /**
     * Gets a collection of size $size of provided type. Options can be used to add extra attributeToFilter options.
     *
     * @param $type
     * @param $size
     * @param array $options
     * @return array
     */
    public static function getRandomCollection($type, $size, $options = array())
    {
        $collection = Mage::getModel($type)->getCollection();
        $collection->getSelect()->order(new Zend_Db_Expr('RAND()'));

        if (method_exists($collection, 'addAttributeToSelect')) {
            $collection->addAttributeToSelect('*');
        }

        /**
         * Add options if set.
         */
        foreach ($options as $attribute => $option) {
            try {
                if (method_exists($collection, 'addAttributeToFilter')) {
                    $collection->addAttributeToFilter($attribute, $option);
                } else {
                    $collection->addFieldToFilter($attribute, $option);
                }
            } catch (Exception $e) {
                echo "Adding attribute filter for $attribute is not possible!";
                echo '<pre>';
                print_r($e->getMessage());
                exit;
            }
        }

        if (method_exists($collection, 'addStoreFilter')) {
            $collection->addStoreFilter();
        }

        $collection->setPageSize($size)->load();

        $objectArray = array();
        foreach ($collection as $object) {
            $objectArray[] = $object;
        }

        return $objectArray;
    }

    /**
     * Get all registry indexes, and possibly add all values as well.
     *
     * @param bool $dumpValues
     * @return array
     */
    public static function getRegistry($dumpValues = false, $silent = false)
    {
        $class = new ReflectionClass('Mage');
        $prop  = $class->getProperty('_registry');
        $prop->setAccessible(true);
        $registry = $prop->getValue();

        if (!$silent) {
            echo '<pre>';
            foreach ($registry as $key => $value) {
                echo '[' . $key . ']';
                if ($dumpValues) {
                    if (strpos($key, '_') !== 0) {
                        echo ' => ' . $value;
                    } else {
                        echo ' => [alot]';
                    }
                }
                echo PHP_EOL;
            }
            echo '</pre>';
        }

        return $registry;
    }

    /**
     * Walks through a collection and calls the callback function on each item in the collection.
     *
     * Default behaviour:
     *  - Calls dump($args)
     *   - Dumps all args
     *
     * @param Varien_Data_Collection_Db $collection
     * @param string $callBack
     */
    public static function iterate(Varien_Data_Collection_Db $collection, $callBack = 'dump')
    {
        Mage::getSingleton('core/resource_iterator')->walk(
            $collection->getSelect(),
            array(array('T', $callBack))
        );
    }

    /**
     * Callback function for iterator.
     *
     * @param $args
     */
    public function dump($args)
    {
        echo '<pre>';
        print_r($args);
        echo '</pre>';
    }

    /**
     * Dumps the current layout handles being used.
     */
    public function getLayoutHandles()
    {
        return $this->getLayout()->getUpdate()->getHandles();
    }

    public static function getConfig()
    {
        return Mage::app()->getConfig()->addAllowedModules();
    }

    /**
     * Gets the select SQL from a collection, select Object, or simply from a string
     * and formats this using sqlFormatter.
     *
     * @param $sql
     * @param $options
     * @return String
     */
    public static function getSql($sql, $options = array())
    {
        /**
         * If we got a collection, get the select statement from the collection.
         */
        if ($sql instanceof Varien_Data_Collection) {
            $sql = $sql->getSelect();
        }

        /**
         * If we have a Varien_Db_Select object (from $collection->getSelect()), we want to
         * parse this to a string.
         */
        if ($sql instanceof Varien_Db_Select) {
            $sql = $sql->__toString();
        }


        if (isset($options['console'])) {
            return $sql;
        }

        return SqlFormatter::format($sql);
    }

    /**
     * Prints a SQL string from Magento in a human-readable form
     *
     * @param $sql
     */
    public static function printSql($sql)
    {
        self::debug(self::getSql($sql), false);
    }

    /**
     * Prints an object, array or string to console rather than on screen.
     *
     * @param $str
     */
    public static function printToConsole($str)
    {
        if (is_object($str) || is_array($str)) {
            $str = json_encode($str);
            echo "<script>console.log($str);</script>";
            return;
        }

        echo "<script>console.log('". $str ."');</script>";
    }

    /**
     * Function used for A/B performance testing.
     *
     * Usage:
     * Call abTest with $object (object on which a function has to be called directly on)
     * and $function (function which is called on object).
     *
     * Within the function, create 2 if-statements with the conditions if(T::ab('a')) and if (T::ab('b')).
     * The execution of the full function will be timed twice; once using test 'a' and once using test 'b'.
     * Afterwards, the time of both executions is compared and this will display the difference.
     *
     * @param $object
     * @param $function
     * @param array $options
     */
    public static function abTest($object, $function, $options = array())
    {
        $options = self::parseOptions($options);

        Mage::register('a', true);
        self::performanceCheck('start', 'a');
        call_user_func(array($object, $function));
        $aPerf = self::performanceCheck('stop', 'a');
        Mage::unregister('a');

        Mage::register('b', true);
        self::performanceCheck('start', 'b');
        call_user_func(array($object, $function));
        $bPerf = self::performanceCheck('stop', 'b');
        Mage::unregister('b');

        $array = array('a' => $aPerf, 'b' => $bPerf);
        asort($array);

        /**
         * Get first value and key from the results.
         */
        $fastestValue = reset($array);
        $fastestKey = key($array);

        /**
         * Get second value and key from the results.
         */
        $slowestValue = end($array);
        $slowestKey = key($array);

        $byHowMuch = $slowestValue / $fastestValue;

        $results = array('%faster' => $byHowMuch, $fastestKey => $fastestValue, $slowestKey => $slowestValue);

        self::printt($results, $options);
    }

    /**
     * Quick wrapper for getting the registry keys for the abTest functions.
     *
     * @param $val
     * @return mixed
     */
    public static function ab($val)
    {
        return Mage::registry($val);
    }
}
