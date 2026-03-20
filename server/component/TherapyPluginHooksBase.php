<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../component/BaseHooks.php";

/**
 * Shared base class for therapy plugin hook classes.
 *
 * Keeps plugin-level behavior in one place (e.g. plugin DB version lookup)
 * while allowing multiple focused hook classes in this plugin.
 */
class TherapyPluginHooksBase extends BaseHooks
{
    const THERAPY_PLUGIN_DB_NAME = 'llm_therapy_chat';

    /**
     * Return this plugin's DB version for CMS version overview.
     *
     * @param string $plugin_name
     * @return string
     */
    public function get_plugin_db_version($plugin_name = self::THERAPY_PLUGIN_DB_NAME)
    {
        return parent::get_plugin_db_version($plugin_name);
    }
}
?>
