<?php

$admin = file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$script = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$requirements = array(
    strpos($admin, 'function prefers_https') !== false,
    strpos($admin, "array('https://', 'http://')") !== false,
    strpos($admin, "array('http://', 'https://')") !== false,
    strpos($script, "addHttpsPreferenceControl(document.getElementById('addSiteForm'), 'add_prefer_https', true)") !== false,
    strpos($script, "addHttpsPreferenceControl(document.getElementById('batchImportForm'), 'batch_prefer_https', true)") !== false,
    strpos($script, '优先 HTTPS') !== false,
    strpos($script, 'checkbox.checked = true') !== false,
    strpos($script, 'getHttpsPreferenceCheckbox') !== false,
    strpos($script, "optionsRow.className = 'domain-options'") !== false,
    strpos($script, 'optionsRow.insertBefore(label, hidden.nextSibling)') !== false,
);
if (in_array(false, $requirements, true)) {
    fwrite(STDERR, "HTTPS_PREFERENCE_FAILED\n");
    exit(1);
}
echo "HTTPS_PREFERENCE_OK\n";
