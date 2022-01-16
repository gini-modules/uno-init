<?php

namespace Gini\Controller\CLI\Data\Init;

const CONST_PATTERN = '/\{\{\{\s*(.+)\s*\}\}\}/';
const MOUSTACHE_PATTERN = '/\{\{([A-Z0-9_]+?)\s*(?:\:\=\s*(.+?))?\s*\}\}/i';
const BASH_PATTERN = '/\$\{([A-Z0-9_]+?)\s*(?:\:\=\s*(.+?))?\s*\}/i';

class Uno extends \Gini\Controller\CLI
{

    private function loadUnoFromFile($file, &$unoConf)
    {
        $content = file_get_contents($file);
        $content = preg_replace_callback(CONST_PATTERN, function ($matches) {
            return constant($matches[1]);
        }, $content);

        $replaceCallback = function ($matches) {
            $name = $matches[1];
            $defaultValue = $matches[2] ?? '';
            $envValue = \Gini\CLI\Env::get($name, $defaultValue);
            return $envValue;
        };

        $content = preg_replace_callback(MOUSTACHE_PATTERN, $replaceCallback, $content);
        $content = preg_replace_callback(BASH_PATTERN, $replaceCallback, $content);
        $conf = @yaml_parse(trim($content));
        if ($conf && is_array($conf)) {
            $unoConf = array_merge($unoConf, $conf);
        }
    }

    private function loadUnoFromInitDir($initDir, &$unoConf)
    {
        if (!is_dir($initDir)) {
            return;
        }

        $filePath = "$initDir/uno.yml";
        if (file_exists($filePath)) {
            $this->loadUnoFromFile($filePath, $unoConf);
        }

        $filePath = "$initDir/uno.yaml";
        if (file_exists($filePath)) {
            $this->loadUnoFromFile($filePath, $unoConf);
        }
    }

    function __index($args)
    {
        $opt = \Gini\Util::getOpt($args, 't:', ['token:']);

        $token = $opt['t'] ?? $opt['token'] ?? null;
        if (!$token) {
            die("Usage: data init uno -t <access_token>\n");
        }

        $paths = \Gini\Core::pharFilePaths(RAW_DIR, 'init');
        $unoConf = [];
        foreach ($paths as $path) {
            $this->loadUnoFromInitDir($path, $unoConf);
        }

        $gatewayUrl = \Gini\Config::get('gapper.gateway_url');
        if (!$gatewayUrl) {
            die("Please configure gapper.gateway_url!\n");
        }

        $rest = \Gini\REST::of($gatewayUrl);
        $rest->header('X-Gapper-OAuth-Token', $token);

        foreach ($unoConf as $appId => $appConf) {
            $body = array_merge([
                'client_id' => $appId,
                'type' => 'group',
                'rate' => 1,
                'icon_url' => '',
                'active' => 1,
                'show' => 1,
            ], $appConf);

            try {
                $rest->put("api/v1/app/$appId", $body);
            } catch (\Gini\REST\Exception $e) {
                $code = $e->getCode();
                $message = $e->getMessage();
                die("Failed to register $appId with error: [$code] $message");
            }
        }
    }
}
