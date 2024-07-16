<?php
declare(strict_types=1);

namespace Ultra\Interfaces;

interface ConfigInterface
{

    /**
     * Determine if the given configuration value exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Get the specified configuration value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key = null, $default = null);

    /**
     * Set a given configuration value.
     *
     * @param array|string $key
     * @param mixed $value
     */
    public function set($key, $value = null): void;
}