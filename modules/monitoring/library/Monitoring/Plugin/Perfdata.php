<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Module\Monitoring\Plugin;

use InvalidArgumentException;

class Perfdata
{
    /**
     * The performance data value being parsed
     *
     * @var string
     */
    protected $perfdataValue;

    /**
     * Unit of measurement (UOM)
     *
     * @var string
     */
    protected $unit;

    /**
     * The label
     *
     * @var string
     */
    protected $label;

    /**
     * The value
     *
     * @var float
     */
    protected $value;

    /**
     * The minimum value
     *
     * @var float
     */
    protected $minValue;

    /**
     * The maximum value
     *
     * @var float
     */
    protected $maxValue;

    /**
     * The WARNING threshold
     *
     * @var string
     */
    protected $warningThreshold;

    /**
     * The CRITICAL threshold
     *
     * @var string
     */
    protected $criticalThreshold;

    /**
     * Create a new Perfdata object based on the given performance data label and value
     *
     * @param   string      $label      The perfdata label
     * @param   string      $value      The perfdata value
     */
    public function __construct($label, $value)
    {
        $this->perfdataValue = $value;
        $this->label = $label;
        $this->parse();

        if ($this->unit === '%') {
            if ($this->minValue === null) {
                $this->minValue = 0.0;
            }
            if ($this->maxValue === null) {
                $this->maxValue = 100.0;
            }
        }
    }

    /**
     * Return a new Perfdata object based on the given performance data key=value pair
     *
     * @param   string      $perfdata       The key=value pair to parse
     *
     * @return  Perfdata
     *
     * @throws  InvalidArgumentException    In case the given performance data has no content or a invalid format
     */
    public static function fromString($perfdata)
    {
        if (empty($perfdata)) {
            throw new InvalidArgumentException('Perfdata::fromString expects a string with content');
        } elseif (false === strpos($perfdata, '=')) {
            throw new InvalidArgumentException(
                'Perfdata::fromString expects a key=value formatted string. Got "' . $perfdata . '" instead'
            );
        }

        list($label, $value) = explode('=', $perfdata, 2);
        return new static(trim($label), trim($value));
    }

    /**
     * Return whether this performance data's value is a number
     *
     * @return  bool    True in case it's a number, otherwise False
     */
    public function isNumber()
    {
        return $this->unit === null;
    }

    /**
     * Return whether this performance data's value are seconds
     *
     * @return  bool    True in case it's seconds, otherwise False
     */
    public function isSeconds()
    {
        return in_array($this->unit, array('s', 'ms', 'us'));
    }

    /**
     * Return whether this performance data's value is in percentage
     *
     * @return  bool    True in case it's in percentage, otherwise False
     */
    public function isPercentage()
    {
        return $this->unit === '%';
    }

    /**
     * Return whether this performance data's value is in bytes
     *
     * @return  bool    True in case it's in bytes, otherwise False
     */
    public function isBytes()
    {
        return in_array($this->unit, array('b', 'kb', 'mb', 'gb', 'tb'));
    }

    /**
     * Return whether this performance data's value is a counter
     *
     * @return  bool    True in case it's a counter, otherwise False
     */
    public function isCounter()
    {
        return $this->unit === 'c';
    }

    /**
     * Return this perfomance data's label
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * Return the value or null if it is unknown (U)
     *
     * @return  null|float
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Return the value as percentage (0-100)
     *
     * @return  null|float
     */
    public function getPercentage()
    {
        if ($this->isPercentage()) {
            return $this->value;
        }

        if ($this->maxValue !== null) {
            $minValue = $this->minValue !== null ? $this->minValue : 0;
            if ($this->maxValue - $minValue === 0.0) {
                return null;
            }

            if ($this->value >= $minValue) {
                return (($this->value - $minValue) / ($this->maxValue - $minValue)) * 100;
            }
        }
    }

    /**
     * Return this performance data's warning treshold or null if it is not available
     *
     * @return  null|string
     */
    public function getWarningThreshold()
    {
        return $this->warningThreshold;
    }

    /**
     * Return this performance data's critical treshold or null if it is not available
     *
     * @return  null|string
     */
    public function getCriticalThreshold()
    {
        return $this->criticalThreshold;
    }

    /**
     * Return the minimum value or null if it is not available
     *
     * @return  null|float
     */
    public function getMinimumValue()
    {
        return $this->minValue;
    }

    /**
     * Return the maximum value or null if it is not available
     *
     * @return  null|float
     */
    public function getMaximumValue()
    {
        return $this->maxValue;
    }

    /**
     * Return this performance data as string
     *
     * @return  string
     */
    public function __toString()
    {
        return sprintf(strpos($this->label, ' ') === false ? '%s=%s' : "'%s'=%s", $this->label, $this->perfdataValue);
    }

    /**
     * Parse the current performance data value
     *
     * @todo    Handle optional min/max if UOM == %
     */
    protected function parse()
    {
        $parts = explode(';', $this->perfdataValue);

        $matches = array();
        if (preg_match('@^(\d+(\.\d+)?)([a-zA-Z%]{1,2})$@', $parts[0], $matches)) {
            $this->unit = strtolower($matches[3]);
            $this->value = self::convert($matches[1], $this->unit);
        } else {
            $this->value = self::convert($parts[0]);
        }

        switch (count($parts))
        {
            case 5:
                if ($parts[4] !== '') {
                    $this->maxValue = self::convert($parts[4], $this->unit);
                }
            case 4:
                if ($parts[3] !== '') {
                    $this->minValue = self::convert($parts[3], $this->unit);
                }
            case 3:
                // TODO(#6123): Tresholds have the same UOM and need to be converted as well!
                $this->criticalThreshold = trim($parts[2]) ? trim($parts[2]) : null;
            case 2:
                // TODO(#6123): Tresholds have the same UOM and need to be converted as well!
                $this->warningThreshold = trim($parts[1]) ? trim($parts[1]) : null;
        }
    }

    /**
     * Return the given value converted to its smallest supported representation
     *
     * @param   string      $value      The value to convert
     * @param   string      $fromUnit   The unit the value currently represents
     *
     * @return  null|float              Null in case the value is not a number
     */
    protected static function convert($value, $fromUnit = null)
    {
        if (is_numeric($value)) {
            switch ($fromUnit)
            {
                case 'us':
                    return $value / pow(10, 6);
                case 'ms':
                    return $value / pow(10, 3);
                case 'tb':
                    return floatval($value) * pow(2, 40);
                case 'gb':
                    return floatval($value) * pow(2, 30);
                case 'mb':
                    return floatval($value) * pow(2, 20);
                case 'kb':
                    return floatval($value) * pow(2, 10);
                default:
                    return (float) $value;
            }
        }
    }
}
