<?php

declare(strict_types=1);

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
     * @return \Closure
     */
    public function argumentHasValue(string $argument = ''): \Closure
    {
        return fn(string $argument): bool => $this->hasArgument($argument) && $this->argument($argument);
    }
    
    /**
     * Check if the option exists and has a value.  
     * 
     * @param string $option
     * @return \Closure
     */
    public function optionHasValue(string $option = ''): \Closure
    { 
        return fn(string $option): bool => $this->hasOption($option) && $this->option($option);
    }
}
