<?php
/**
 * Before step hook that provide parameter syntax notation
 * for accessing fixture data between Gherkin steps/tests
 * example:
 *  I see "{{param}}"
 *  {{param}} will be replaced by the value of Fixtures::get('param')
 *
 */
namespace Codeception\Extension;

class GherkinParam extends \Codeception\Platform\Extension
{
    /**
    * @var array List events to listen to
    */
    public static $events = [
        //run before any suite
        'suite.before' => 'beforeSuite',
        //run before any steps
        'step.before' => 'beforeStep'
    ];

    /**
     * @var array Current test suite config
     */
    private static $suiteConfig;

    const REGEX_PARAM = '/^{{[A-z0-9_:-]+}}$/';
    const REGEX_FILTER = '/[{}]/';
    const REGEX_CONFIG = '/(?:^config)?:([A-z0-9_-]+)+(?=:|$)/';
    const REGEX_ARRAY = '/^(?P<var>[A-z0-9_-]+)(?:\[(?P<key>.+)])$/';

    private function getFixtureValue($val)
    {
        $fixtures = new \Codeception\Util\Fixtures();
        return $fixtures::get($val);
    }

    /**
     * Parse param and replace {{.*}} by its Fixtures::get() value if exists
     *
     * @param string $param
     *
     * @return mixed|string Return parameter's value if exists, else parameter's name
     */
    protected function getValueFromParam($param)
    {
        if (!preg_match(self::REGEX_PARAM, $param)) {
            return $param;
        }

        $arg = preg_filter(self::REGEX_FILTER, '', $param);

        if (preg_match(self::REGEX_CONFIG, $arg)) {
            return $this->getValueFromConfig($arg);
        }

        if (preg_match(self::REGEX_ARRAY, $arg)) {
            return $this->getValueFromArray($arg);
        }

        return $this->getFixtureValue($arg);
    }

    /**
     * Retrieve param value from current suite config
     *
     * @param string $param
     *
     * @return mixed|null Return parameter's value if exists, else null
     */
    protected function getValueFromConfig($param)
    {
        $value = null;
        $config = self::$suiteConfig;

        preg_match_all(self::REGEX_CONFIG, $param, $args, PREG_PATTERN_ORDER);
        foreach ($args[1] as $arg) {
            if (array_key_exists($arg, $config)) {
                $value = $config[$arg];
                if (!is_array($value)) {
                    break;
                }
                $config = $value;
            }
        }
        return $value;
    }

    /**
     * Retrieve param value from array in Fixtures
     *
     * @param string $param
     *
     * @return mixed|null Return parameter's value if exists, else null
     */
    protected function getValueFromArray($param)
    {
        $value = null;

        preg_match_all(self::REGEX_ARRAY, $param, $args);
        $array = $this->getFixtureValue($args['var'][0]);
        if (array_key_exists($args['key'][0], $array)) {
            $value = $array[$args['key'][0]];
        }
        return $value;
    }

    /**
     * Retrieve param value from Gherkin table row
     *
     * @param array $tableNodeRows
     *
     * @return Behat\Gherkin\Node\TableNode Return table node valued
     */
    protected function getValueFromTableNode(array $tableNodeRows)
    {
        $table = [];
        foreach ($tableNodeRows as $i => $row) {
            foreach ($row as $j => $cell) {
                $table[$i][$j] = $this->getValueFromParam($cell);
            }
        }
        return new \Behat\Gherkin\Node\TableNode($table);
    }

    /**
     * Capture suite's config before any execution
     *
     * @param \Codeception\Event\SuiteEvent $e
     *
     * @codeCoverageIgnore
     * @ignore Codeception specific
     */
    public function beforeSuite(\Codeception\Event\SuiteEvent $event)
    {
        self::$suiteConfig = $event->getSettings();
    }

    /**
     * Parse scenario's step before execution
     *
     * @param \Codeception\Event\StepEvent $e
     */
    public function beforeStep(\Codeception\Event\StepEvent $event)
    {
        $step = $event->getStep();
        // access to the protected property using reflection
        $refArgs = new \ReflectionProperty(get_class($step), 'arguments');
        // change property accessibility to public
        $refArgs->setAccessible(true);
        // retrieve 'arguments' value
        $args = $refArgs->getValue($step);
        foreach ($args as $index => $arg) {
            switch (true) {
                // case I see "{{param}}"
                case (is_string($arg)):
                    $args[$index] = $this->getValueFromParam($arg);
                    break;
                // case Gherkin table
                //  | paramater |
                //  | {{param}} |
                case (is_a($arg, '\Behat\Gherkin\Node\TableNode')):
                    $tableNodeRows = $arg->getRows();
                    $args[$index] = $this->getValueFromTableNode($tableNodeRows);
                    break;
            }
        }
        // set new arguments value
        $refArgs->setValue($step, $args);
    }
}
