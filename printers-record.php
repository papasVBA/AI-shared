<?php
require_once '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\PgConnection;
use LabelCenter\Db\BaseRecordHandler;

$pg = new PgConnection('labelcenter');

$mapping = [
    'id'      => 'id',
    'name'    => 'name',
    'sysname' => 'sysname',
    'notice'  => 'notice'
];

$handler = new BaseRecordHandler($pg, 'printers', 'public.spelman_lc_printers', $mapping);
$handler->handleRequest();
