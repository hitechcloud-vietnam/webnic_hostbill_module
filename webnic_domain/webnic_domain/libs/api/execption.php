<?php
namespace Domain\webnic_domain\Libs\API;

/**
 * WebNIC API exception carrying the sub-code and field/validation errors
 * returned in a WebNIC error envelope.
 *
 * @package Domain\webnic_domain\Libs\API
 */
class Exception extends \Exception
{
    /** @var string|null */
    protected $subCode;

    /** @var array */
    protected $fieldErrors;

    /** @var array */
    protected $validationErrors;

    public function __construct($message, $code = 0, $subCode = null, $fieldErrors = [], $validationErrors = [], \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->subCode          = $subCode;
        $this->fieldErrors      = $fieldErrors;
        $this->validationErrors = $validationErrors;
    }

    public function getSubCode()
    {
        return $this->subCode;
    }

    public function getFieldErrors()
    {
        return $this->fieldErrors;
    }

    public function getValidationErrors()
    {
        return $this->validationErrors;
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
