<?php
class PluginLoader {
    private $pluginsPath;
    private $loadedPlugins = [];

    public function __construct($pluginsPath) {
        $this->pluginsPath = $pluginsPath;
    }

    public function loadPlugins() {
        if (!is_dir($this->pluginsPath)) {
            return;
        }

        $plugins = scandir($this->pluginsPath);
        foreach ($plugins as $plugin) {
            if ($plugin === '.' || $plugin === '..') continue;
            
            $pluginDir = $this->pluginsPath . '/' . $plugin;
            if (is_dir($pluginDir)) {
                $this->loadPlugin($plugin, $pluginDir);
            }
        }

        return $this->loadedPlugins;
    }

    private function loadPlugin($pluginName, $pluginDir) {
        $phpFile = $pluginDir . '/' . $pluginName . '.php';
        $jsFile = $pluginDir . '/' . $pluginName . '.js';

        if (file_exists($phpFile)) {
            require_once $phpFile;
            $this->loadedPlugins[$pluginName] = [
                'name' => $pluginName,
                'js' => file_exists($jsFile) ? '/xshow/plugins/' . $pluginName . '/' . $pluginName . '.js' : null
            ];
        }
    }

    public function getLoadedPlugins() {
        return $this->loadedPlugins;
    }
}