<?php

$admin = file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$script = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');

$requirements = array(
    strpos($admin, "if (\$action === 'inspect_site_json')") !== false,
    strpos($admin, 'id="addSiteForm"') !== false,
    strpos($admin, 'type="button" data-inspect-add-site') !== false,
    strpos($admin, 'name="action" value="save_site"') !== false,
    strpos($script, "payload.set('action', 'inspect_site_json')") !== false,
    strpos($script, 'showAddReadMessage') !== false,
    strpos($script, "openModal('addSiteModal')") !== false,
);

if (in_array(false, $requirements, true)) {
    fwrite(STDERR, "ADMIN_ADD_INSPECT_ASYNC_FAILED\n");
    exit(1);
}

echo "ADMIN_ADD_INSPECT_ASYNC_OK\n";
