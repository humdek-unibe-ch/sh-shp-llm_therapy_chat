<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/TherapyPromptAssetRegistry.php';

class TherapyPromptAssetLoader
{
    private $base_dir;
    private static $cache = array();

    public function __construct($base_dir = null)
    {
        $this->base_dir = $base_dir ?: __DIR__ . '/../../../assets/prompts';
    }

    public function load($key)
    {
        $key = trim((string)$key);
        if ($key === '') {
            throw new RuntimeException('Therapy prompt asset key must not be empty');
        }

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $map = TherapyPromptAssetRegistry::getMap();
        if (!isset($map[$key])) {
            throw new RuntimeException('Therapy prompt asset key is not registered: ' . $key);
        }

        $relative_path = ltrim((string)$map[$key], '/\\');
        $full_path = $this->base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);

        if (!file_exists($full_path)) {
            throw new RuntimeException('Therapy prompt asset file not found for key ' . $key . ': ' . $full_path);
        }

        $content = file_get_contents($full_path);
        if ($content === false) {
            throw new RuntimeException('Failed to read therapy prompt asset for key ' . $key . ': ' . $full_path);
        }

        $content = trim($content);
        if ($content === '') {
            throw new RuntimeException('Therapy prompt asset is empty for key ' . $key . ': ' . $full_path);
        }

        self::$cache[$key] = $content;
        return self::$cache[$key];
    }
}
?>
