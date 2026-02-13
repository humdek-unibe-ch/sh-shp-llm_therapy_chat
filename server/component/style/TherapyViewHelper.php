<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapy View Helper
 *
 * Shared utilities for therapy chat view classes.
 * Eliminates duplication of asset versioning logic across views.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyViewHelper
{
    /**
     * Get a versioned asset path for cache busting.
     *
     * In DEBUG mode uses file modification time; in production uses
     * the plugin version constant.
     *
     * @param string $filePath Absolute path to the asset file
     * @return string|null Versioned path or null if file doesn't exist
     */
    public static function getVersionedAssetPath($filePath)
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $version = defined('LLM_THERAPY_CHAT_PLUGIN_VERSION')
            ? LLM_THERAPY_CHAT_PLUGIN_VERSION
            : '1.0.0';

        if (defined('DEBUG') && DEBUG) {
            $version = filemtime($filePath) ?: time();
        }

        return $filePath . "?v=" . $version;
    }

    /**
     * Get versioned CSS include path for the therapy chat plugin.
     *
     * @param string $baseDir __DIR__ of the calling view
     * @return array Array with single versioned path, or empty
     */
    public static function getCssPath($baseDir)
    {
        $cssFile = $baseDir . "/../../../../css/ext/therapy-chat.css";
        $path = self::getVersionedAssetPath($cssFile);
        return $path ? array($path) : array();
    }

    /**
     * Get versioned JS include path for the therapy chat plugin.
     *
     * @param string $baseDir __DIR__ of the calling view
     * @return array Array with single versioned path, or empty
     */
    public static function getJsPath($baseDir)
    {
        $jsFile = $baseDir . "/../../../../js/ext/therapy-chat.umd.js";
        $path = self::getVersionedAssetPath($jsFile);
        return $path ? array($path) : array();
    }
}
