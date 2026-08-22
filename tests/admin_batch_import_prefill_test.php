<?php

$admin = file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$script = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$requirements = array(
    strpos($admin, "} elseif (\$action === 'bulk_import_sites') {") !== false,
    strpos($admin, 'window.batchImportPrefill') !== false,
    strpos($admin, 'id="batchImportForm"') !== false,
    strpos($script, 'showBatchImportMessage') !== false,
    strpos($script, 'openModal(\'batchImportModal\')') !== false,
    strpos($script, 'batchImportForm.elements.batch_sites.value') !== false,
);
if (in_array(false, $requirements, true)) {
    fwrite(STDERR, "ADMIN_BATCH_IMPORT_PREFILL_FAILED\n");
    exit(1);
}
echo "ADMIN_BATCH_IMPORT_PREFILL_OK\n";
