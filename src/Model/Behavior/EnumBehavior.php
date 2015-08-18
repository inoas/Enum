<?php
namespace Enum\Model\Behavior;

use ArrayObject;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\RulesChecker;
use Cake\Utility\Inflector;
use Enum\Model\Behavior\Strategy\AbstractStrategy;
use InvalidArgumentException;
use RuntimeException;

class EnumBehavior extends Behavior
{
    /**
     * Default configuration.
     *
     * - `defaultStrategy`: the default strategy to use.
     * - `implementedMethods`: custom table methods made accessible by this behavior.
     * - `strategies`: the defined enumeration lists. Providers can use different strategies,
     *   use prefixes to differentiate them (defaults to the uppercased provider name) and
     *   are persisted into a table's field (default to the underscored provider name).
     *
     *   Example:
     *
     *   ```php
     *   $strategies = [
     *       'priority' => [
     *           'className' => 'lookup',
     *           'prefix' => 'PRIORITY',
     *           'field' => 'priority',
     *       ],
     *   ];
     *   ```
     *
     * @var array
     */
    protected $_defaultConfig = [
        'defaultStrategy' => 'lookup',
        'implementedMethods' => [
            'enum' => 'enum',
        ],
        'strategies' => [],
    ];

    /**
     * Class map.
     *
     * @var array
     */
    protected $_classMap = [
        'lookup' => 'Enum\Model\Behavior\Strategy\LookupStrategy',
        'const' => 'Enum\Model\Behavior\Strategy\ConstStrategy',
        'enum' => 'Enum\Model\Behavior\Strategy\EnumStrategy',
    ];

    /**
     * Stack of strategies in use.
     *
     * @var array
     */
    protected $_strategies = [];

    /**
     * Initializes the behavior.
     *
     * @return void
     */
    public function initialize(array $config)
    {
        parent::initialize($config);
        $this->_normalizeConfig();
    }

    /**
     * Getter/setter for strategies.
     *
     * @param string $alias
     * @param mixed $strategy Strategy name from the classmap,
     * @return \Enum\Model\Behavior\Strategy\AbstractStrategy
     */
    public function strategy($alias, $strategy)
    {
        if (!empty($this->_strategies[$alias])) {
            return $this->_strategies[$alias];
        }

        $this->_strategies[$alias] = $strategy;
        if (!($strategy instanceof AbstractStrategy)) {
            if (isset($this->_classMap[$strategy])) {
                $class = $this->_classMap[$strategy];
            }

            if (!class_exists($class)) {
                throw new InvalidArgumentException(sprintf('Class not found for strategy (%s)', $strategy));
            }

            $this->_strategies[$alias] = new $class($alias, $this->_table);
        }

        return $this->_strategies[$alias];
    }

    /**
     * Normalizes the strategies configuration and initializes the strategies.
     *
     * @return void
     */
    protected function _normalizeConfig()
    {
        $strategies = $this->config('strategies');
        $strategy = $this->config('defaultStrategy');

        foreach ($strategies as $alias => $config) {
            if (is_numeric($alias)) {
                unset($strategies[$alias]);
                $alias = $config;
                $config = [];
                $strategies[$alias] = $config;
            }

            if (is_string($config)) {
                $config = ['prefix' => strtoupper($config)];
            }

            if (empty($config['className'])) {
                $config['className'] = $strategy;
            }

            $strategies[$alias] =  $this->strategy($alias, $config['className'])
               ->normalize($config);
        }

        $this->config('strategies', $strategies, false);
    }

    /**
     * @param string $alias Defined list's alias/name.
     * @return array
     */
    public function enum($alias)
    {
        $config = $this->config('strategies.' . $alias);
        if (empty($config)) {
            throw new RuntimeException();
        }

        return $this->strategy($alias, $config['className'])->enum($config);
    }

    /**
     * @param \Cake\Event\Event $event Event.
     * @param \Cake\ORM\RulesChecker $rules Rules checker.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(Event $event, RulesChecker $rules)
    {
        foreach ($this->config('strategies') as $alias => $config) {
            $ruleName = 'isValid' . Inflector::classify($alias);
            $rules->add([$this, $ruleName], $ruleName, [
                'errorField' => $config['field'],
                'message' => $config['errorMessage']
            ]);
        }
        return $rules;
    }

    /**
     * Universal validation rule for strategies.
     *
     * @param string $method
     * @param array $args
     */
    public function __call($method, $args)
    {
        if (strpos($method, 'isValid') !== 0) {
            throw new RuntimeException();
        }

        $alias = Inflector::underscore(str_replace('isValid', '', $method));

        if (!$config = $this->config('strategies.' . $alias)) {
            throw new RuntimeException();
        }

        list($entity, $options) = $args;

        $value = $entity->{$config['field']};
        return array_key_exists($entity->{$config['field']}, $this->enum($alias));
    }
}
