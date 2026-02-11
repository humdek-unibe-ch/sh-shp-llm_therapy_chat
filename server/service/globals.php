<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * LLM Therapy Chat Plugin - Global Configuration
 *
 * Loaded automatically by SelfHelp::loadPluginGlobals().
 * Defines plugin-level constants and loads all lookup constants.
 *
 * @package LLM Therapy Chat Plugin
 */

// Plugin identification
define('LLM_THERAPY_CHAT_PLUGIN_NAME', 'sh-shp-llm_therapy_chat');
define('LLM_THERAPY_CHAT_PLUGIN_VERSION', 'v1.0.1');

// Load all therapy lookup constants
require_once __DIR__ . "/../constants/TherapyLookups.php";
