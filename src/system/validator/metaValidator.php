<?php

namespace mpcmf\system\validator;

use mpcmf\system\helper\io\log;
use mpcmf\system\validator\exception\validatorException;

/**
 * Validator class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @author Oleg Andreev <ustr3ts@gmail.com>
 */
class metaValidator
{
    use log;

    private $rules = [];

    /**
     * Instantiate validator
     *
     * @param array|null $rules
     */
    public function __construct($rules = null)
    {
        if ($rules !== null) {
            $this->setRules($rules);
        }
    }

    /**
     * Validate input by set rules
     *
     * @param $input
     *
     * @return bool
     * @throws validatorException
     */
    public function validate($input)
    {
        foreach ($this->getRules() as $rule) {
            $this->validateByRule($input, $rule);
        }

        return true;
    }

    /**
     * Validate input by rule
     *
     * @param $input
     * @param $rule
     *
     * @return bool
     * @throws validatorException
     */
    public function validateByRule($input, $rule)
    {
        list($class, $method) = explode('.', $rule['type']);
        $class = __NAMESPACE__ . "\\{$class}Validator";
        if(!class_exists($class)) {
            throw new validatorException("Unknown validator rule type class `{$rule['type']}` => {$class}");
        }
        if(!method_exists($class, $method)) {
            throw new validatorException("Unknown validator rule type method `{$rule['type']}`");
        }

        $result = (bool)call_user_func([$class, $method], $input, $rule['data']);

        if(!$result) {
            throw new validatorException("Validation was failed on rule type `{$rule['type']}`. Input: " . json_encode($input) . ' Rule: ' . json_encode($rule));
        }

        return true;
    }

    /**
     * Set rules
     *
     * @param array $rules
     */
    public function setRules(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * Get rules
     *
     * @return array
     */
    public function getRules()
    {
        return $this->rules;
    }

}
