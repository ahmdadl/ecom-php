<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing\Traits;

trait WithKeyAndValue
{
    /**
     * Unit Value
     * 
     * @var mixed
     */
    protected $value;

    /**
     * Unit Key
     */
    protected string $key = '';

    /**
     * Unit parent key
     */
    protected string $parentKey = '';

    /**
     * Key full namespace
     */
    protected string $keyNamespace = '';

    /**
     * Set unit key namespace
     */
    public function setKeyNamespace(string $keyNamespace): self
    {
        $this->keyNamespace = $keyNamespace;

        return $this;
    }

    /**
     * Set unit parent key
     */
    public function setParentKey(string $parentKey): self
    {
        $this->parentKey = $parentKey;

        return $this;
    }

    /**
     * Set unit value
     *
     * @param mixed $value
     */
    public function setValue($value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Set unit key
     */
    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Get full key path which is the parent key concated with current key
     */
    public function fullKeyPath(): string
    {
        return ($this->keyNamespace ? $this->keyNamespace . '.' : '') . $this->key;
    }

    /**
     * Get error prefixed with the full key name
     *
     * @return string
     */
    public function keyError(string $error)
    {
        return '`' . $this->fullKeyPath() . '` key ' . $error;
    }
}
