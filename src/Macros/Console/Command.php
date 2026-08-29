<?php
namespace HZ\Illuminate\Mongez\Macros\Console;

use Illuminate\Console\Command as IlluminateCommand;

/**
 * @mixin IlluminateCommand
 */
class Command
{
    /**
     * Check if the argument exists and has a value.  
     * 
     * @param string $argument
     * @return bool
     */
    public function argumentHasValue()
    {
        return function (string $argument): bool {
            return $this->hasArgument($argument) && $this->argument($argument);   
        };
    }
    
    /**
     * Check if the option exists and has a value.  
     * 
     * @param string $option
     * @return bool
     */
    public function optionHasValue()
    { 
        return function (string $option): bool {
            return $this->hasOption($option) && $this->option($option);   
        };
    }
}
