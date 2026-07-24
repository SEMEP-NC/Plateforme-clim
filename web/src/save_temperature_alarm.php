<?php

require 'auth.php';
require_admin();

require 'config/db.php';


$db=get_db();
verify_csrf();


$equipment_id=intval($_POST['equipment_id']);


$enabled=
isset($_POST['enabled'])
?1:0;


$high=
$_POST['high_threshold'] !== ''
?$_POST['high_threshold']
:null;


$low=
$_POST['low_threshold'] !== ''
?$_POST['low_threshold']
:null;


$delay=
intval($_POST['delay_minutes'] ?? 5)
*60;



$sql="
INSERT INTO equipment_temperature_alarms

(
equipment_id,
enabled,
high_threshold,
low_threshold,
delay_seconds
)

VALUES

(
?,?,?,?,?
)

ON DUPLICATE KEY UPDATE

enabled=VALUES(enabled),
high_threshold=VALUES(high_threshold),
low_threshold=VALUES(low_threshold),
delay_seconds=VALUES(delay_seconds)

";


$stmt=$db->prepare($sql);


$stmt->execute([

$equipment_id,
$enabled,
$high,
$low,
$delay

]);


header(
"Location: temperature_alarms.php"
);

exit;