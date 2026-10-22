<?php
require_once '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\PgConnection;
use LabelCenter\Db\BaseRecordHandler;

$pg = new PgConnection('labelcenter');

$mapping = [
    'id'            => 'id',
    'label_id'      => 'labelid',
    'field_name'    => 'name',
    'field_content' => 'content',
    'notice'        => 'notice'
];

$handler = new BaseRecordHandler($pg, 'fields', 'public.spelman_lc_fields', $mapping);
$handler->handleRequest();
