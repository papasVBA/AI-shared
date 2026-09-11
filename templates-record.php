<?php
require_once '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\PgConnection;
use LabelCenter\Db\BaseRecordHandler;

$pg = new PgConnection('labelcenter');

$mapping = [
    'id'                => 'id',
    'name'              => 'name',
    'btw_filename'      => 'btwfilename',
    'printer_id'        => 'printerid',
    'storecard_id'      => 'scid',
    'bustransaction_id' => 'btid', // Mapování dle MD: "cd" -> bustransaction_id
    'meta_json'         => 'meta_json',
    'notice'            => 'notice'
];

$handler = new BaseRecordHandler($pg, 'templates', 'public.spelman_lc_templates', $mapping);
$handler->handleRequest();
