<?php

require 'config/db.php';

$db = get_db();

$id = (int)$_POST["equipment_id"];

$stmt = $db->prepare("
SELECT
    ip,
    slave_id,
    UI
FROM equipments
WHERE id=?
");

$stmt->execute([$id]);

$equipment = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$equipment){

    exit(json_encode([
        "success"=>false
    ]));

}

$base = 102 + (25*($equipment["UI"]-1));

function writeRegister($ip,$slave,$address,$value){

    $payload=[

        "ip"=>$ip,

        "port"=>502,

        "slave"=>$slave,

        "address"=>$address,

        "value"=>$value,

        "type"=>"register"

    ];

    $ch=curl_init("http://modbus-hub:8500/write");

    curl_setopt_array($ch,[

        CURLOPT_POST=>true,

        CURLOPT_HTTPHEADER=>[
            "Content-Type: application/json"
        ],

        CURLOPT_RETURNTRANSFER=>true,

        CURLOPT_POSTFIELDS=>json_encode($payload)

    ]);

    curl_exec($ch);

    curl_close($ch);

}

if(isset($_POST["send_power"])){

    writeRegister(

        $equipment["ip"],

        $equipment["slave_id"],

        $base,

        (int)$_POST["power"]

    );

}

if(isset($_POST["send_mode"])){

    writeRegister(

        $equipment["ip"],

        $equipment["slave_id"],

        $base+1,

        (int)$_POST["mode"]

    );

}

if(isset($_POST["send_setpoint"])){

    writeRegister(

        $equipment["ip"],

        $equipment["slave_id"],

        $base+2,

        (int)round($_POST["setpoint"]*10)

    );

}

if(isset($_POST["send_fan"])){

    writeRegister(

        $equipment["ip"],

        $equipment["slave_id"],

        $base+3,

        (int)$_POST["fan"]

    );

}

echo json_encode([
    "success"=>true
]);