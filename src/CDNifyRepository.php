<?php

namespace Metrique\CDNify;

use Metrique\CDNify\Contracts\CDNifyRepositoryInterface;

class CDNifyRepository implements CDNifyRepositoryInterface
{
    private $cdn;
    private $path;
    private $environments;
    private $mix;
    private $renameQueryStrings;

    private $roundRobin;
    private $roundRobinIndex = -1;
    private $roundRobinLength = 0;

    public function __construct()
    {
        $this->defaults();
    }

    /**
     * {@inheritdoc}
     */
    public function defaults()
    {
        $this->cdn = array_values(config('cdnify.cdn', []));
        $this->renameQueryStrings = config('cdnify.rename_query_strings', true);
        $this->roundRobinLength = count($this->cdn);

        $this->mix(config('cdnify.mix', false));
        $this->environments(config('cdnify.environments', []));
        $this->roundRobin(config('cdnify.round_robin'));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get($path, $params = [])
    {
        $resets = collect([
            'mix' => $this->mix,
            'environments' => $this->environments,
            'roundRobin' => $this->roundRobin,
        ]);

        $params = $resets->merge($params)->only($resets->keys()->all());

        $params->each(function ($item, $key) {
            $this->{$key}($item);
        });

        $path = $this->path($path)->toString();

        $resets->each(function ($item, $key) {
            $this->{$key}($item);
        });

        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function toString()
    {
        $path = $this->path ?: false;

        if ($path === false) {
            return false;
        }

        if ($this->mix === true) {
            $path = $this->renameQueryString(
                $this->mixOrElixir($this->path)
            );
        }

        if (in_array(env('APP_ENV'), $this->environments)) {
            return $this->cdn().$path;
        }

        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function cdn($path = null)
    {
        if (!$this->roundRobin) {
            return $this->cdn[0];
        }

        if (++$this->roundRobinIndex > ($this->roundRobinLength - 1)) {
            $this->roundRobinIndex = 0;
        }

        return $this->cdn[$this->roundRobinIndex] . $path ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function path($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function environments($environments)
    {
        if (is_array($environments)) {
            $this->environments = $environments;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function mix($bool)
    {
        if (is_bool($bool)) {
            $this->mix = $bool;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function roundRobin($bool)
    {
        if (is_bool($bool)) {
            $this->roundRobin = $bool;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @param  [type] $path   [description]
     * @param  array  $params [description]
     * @return [type]         [description]
     */
    public function renameQueryString($path, $params = ['key' => 'id', 'separator' => '-'])
    {
        if (!$this->renameQueryStrings) {
            return $path;
        }

        $parsed_path = parse_url($path);
        $pathinfo = pathinfo($parsed_path['path']);

        if (!$parsed_path) {
            return $parsed_path['path'];
        }

        // Check if file is in whitelist
        $isInWhitelist = collect(
            config('cdnify.rename_whitelist', [])
        )->reduce(function ($carry, $item) use ($pathinfo) {
            if ($carry) {
                return $carry;
            }

            return $pathinfo['basename'] == $item;
        }, false);

        if ($isInWhitelist) {
            return $parsed_path['path'];
        }

        // Extract query hash from query string
        parse_str($parsed_path['query'], $query);
        $hash = $query[$params['key']] ?? null;

        if (!empty($hash)) {
            $hash = $params['separator'].$hash;
        }

        // Insert hash before extension.
        return sprintf(
            '%s/%s%s.%s',
            $pathinfo['dirname'],
            $pathinfo['filename'],
            $hash,
            $pathinfo['extension']
        );
    }

    protected function mixOrElixir($path)
    {
        if (config('cdnify.prefer_elixir', false)) {
            if (function_exists('elixir')) {
                return elixir($path);
            }
        }

        if (function_exists('mix')) {
            return mix($path);
        }

        if (function_exists('elixir')) {
            return elixir($path);
        }

        return $path;
    }
}
