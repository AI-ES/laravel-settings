<?php

namespace Elnooronline\LaravelSettings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Elnooronline\LaravelSettings\Models\SettingModel;
use Prophecy\Exception\Doubler\MethodNotFoundException;

class SettingBuilder
{
    /**
     * @var string
     */
    private $lang;

    /**
     * All settings collection.
     *
     * @var \Illuminate\Database\Eloquent\Collection
     */
    private $settings;

    /**
     * @var string
     */
    private $prefix;

    /**
     * @var array
     */
    private $prefixMethods = [];

    /**
     * @var array
     */
    private $conditions = [];

    /**
     * @param $name
     * @param $arguments
     * @return $this
     * @throws \Prophecy\Exception\Doubler\MethodNotFoundException
     */
    public function __call($name, $arguments)
    {
        if (in_array($name, $this->prefixMethods)) {
            $this->conditions[$name] = array_first($arguments);

            return $this;
        }
        throw new MethodNotFoundException("{$name}() method not found!", __CLASS__, $name);
    }

    /**
     * Set the setting locale.
     *
     * @param null $lang
     * @return $this
     */
    public function lang($lang = null)
    {
        $this->lang = $lang;

        return $this;
    }

    /**
     * Register custom prefix method.
     *
     * @param array|string $method
     * @return $this
     */
    public function registerPrefixMethod($method)
    {
        if (is_array($method)) {
            $this->prefixMethods = $method;

            return $this;
        }

        $this->prefixMethods = func_get_args();

        return $this;
    }

    /**
     * Set the global conditions.
     *
     * @param array $conditions
     * @return $this
     */
    public function setConditions(array $conditions = [])
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Get the given setting by key.
     *
     * @param $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $this->supportPrefix($key);

        $this->supportLocaledKey($key);

        if (strpos($key, '.') !== false) {
            $parentKey = explode('.', $key)[0];
            $childrenKeysDoted = str_replace($parentKey.'.', '', $key);

            $value = optional($this->getModel($parentKey))->value;
            $value = $value && $this->isSerialized($value) ? unserialize($value) : $value;

            return data_get($value, $childrenKeysDoted) ?: $default;
        }

        $instance = $this->getModel($key);

        $value = optional($instance)->value;

        $value = $value && $this->isSerialized($value) ? unserialize($value) : $value;

        $this->lang = null;

        return $value ?: $default;
    }

    /**
     * @param $key
     * @return void
     */
    private function supportPrefix(&$key)
    {
        $this->withPrefix();

        if ($this->prefix) {
            $key = "{$this->prefix}{$key}";
        }
    }

    /**
     * @return void
     */
    public function withPrefix()
    {

        foreach ($this->conditions as $k => $condition) {
            if ($this->hasPrefix($k)) {
                $this->prefix = preg_replace("/($k)__([a-zA-Z0-9]+)/", "$1__$condition", $this->prefix);
            } else {
                $this->prefix = "_{$k}__{$condition}_{$this->prefix}";
            }
        }
    }

    /**
     * Determine whether the prefix already exists.
     *
     * @param $method
     * @return bool
     */
    public function hasPrefix($method)
    {
        preg_match("/($method)__/", $this->prefix, $matches);

        return isset($matches[1]) && $matches[1] == $method;
    }

    /**
     * Update lang if the key has the language.
     *
     * @param $key
     */
    private function supportLocaledKey(&$key)
    {
        if (strpos($key, ':') !== false) {
            $this->lang = explode(':', $key)[1];
            $key = explode(':', $key)[0];
        }
    }

    /**
     * Get the model instance for the specified key.
     *
     * @param null $key
     * @return \Elnooronline\LaravelSettings\Models\SettingModel
     */
    public function getModel($key = null)
    {
        $instance = $this->getCollection()->where('locale', $this->lang)->where('key', $key)->first();
        if (! $instance) {
            $model = $this->getModelClassName();

            return new $model;
        }

        return $instance;
    }

    /**
     * Get settings collection.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCollection()
    {
        $this->setCollection();

        return $this->settings;
    }

    private function setCollection()
    {
        if (! $this->settings) {
            $this->settings = $this->query()->get();
        }
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function query()
    {
        $model = $this->getModelClassName();

        return $model::query();
    }

    /**
     * Get the settings model class name.
     *
     * @return string
     */
    private function getModelClassName()
    {
        return Config::get('laravel_settings.model_class', SettingModel::class);
    }

    private function isSerialized($str)
    {
        return ($str == serialize(false) || @unserialize($str) !== false);
    }

    /**
     * Gel the collection of the settings.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all()
    {
        return $this->getCollection();
    }

    /**
     * Update or set setting value.
     *
     * @param $key
     * @param null $value
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function set($key, $value = null)
    {
        if (is_array($value) || is_object($value)) {
            $value = serialize($value);
        }

        $this->supportPrefix($key);
        $this->supportLocaledKey($key);

        $model = $this->getModelClassName();
        if (is_array($key)) {
            foreach ($key as $k => $val) {
                $model::updateOrCreate([
                    'key' => $k,
                    'locale' => $this->lang,
                ], [
                    'value' => $val,
                ]);
            }
        } else {
            $model::updateOrCreate([
                'key' => $key,
                'locale' => $this->lang,
            ], [
                'value' => $value,
            ]);
        }

        $this->resetCollection();

        $this->lang = null;

        return $this->getModel($key);
    }

    /**
     * Set settings collection.
     *
     * @return void
     */
    private function resetCollection()
    {
        $this->lang = null;
        $this->settings = null;
        $this->setCollection();
    }

    /**
     * Delete the specified setting instance.
     *
     * @param $key
     */
    public function forget($key)
    {
        $this->supportPrefix($key);
        $this->supportLocaledKey($key);

        $table = $this->query()->getModel()->getTable();

        DB::table($table)->where('locale', $this->lang)->where('key', $key)->delete();

        $this->resetCollection();
    }

    /**
     * Delete the specified setting instance for all languages.
     *
     * @param $key
     */
    public function forgetAll($key)
    {
        $table = $this->query()->getModel()->getTable();

        DB::table($table)->where('key', $key)->delete();

        $this->resetCollection();
    }

    /**
     * Determine whether the key is not exists.
     *
     * @param $key
     * @return bool
     */
    public function hasNot($key)
    {
        return ! $this->has($key);
    }

    /**
     * Determine whether the key is already exists.
     *
     * @param $key
     * @return bool
     */
    public function has($key)
    {
        $this->supportPrefix($key);
        $this->supportLocaledKey($key);

        return ! ! $this->first($key);
    }

    /**
     * Get the key instanse.
     *
     * @param $key
     * @return bool
     */
    public function first($key)
    {
        return $this->getCollection()->where('locale', $this->lang)->where('key', $key)->first();
    }

    /**
     * Determine whether the key is already exists and the value is not dirty.
     *
     * @param $key
     * @param $value
     * @return bool
     */
    public function is($key, $value)
    {
        return $this->has($key) && $this->first($key)->value == $value;
    }

    /**
     * Determine whether the key is already exists and the value is dirty.
     *
     * @param $key
     * @param $value
     * @return bool
     */
    public function isNot($key, $value)
    {
        return $this->has($key) && $this->first($key)->value != $value;
    }
}