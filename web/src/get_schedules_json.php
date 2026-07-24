<?php
require 'auth.php';
require_login();
require 'config/db.php';
$pdo = get_db();
$events = [];
date_default_timezone_set('Pacific/Noumea');
$stmt = $pdo->query("
    SELECT
        s.id,
        s.execution_time,
        s.repeat_days,
        s.action,
        s.temperature,
        e.name AS equipment_name,
        g.name AS group_name
    FROM schedules s
    LEFT JOIN equipments e
        ON e.id=s.equipment_id
    LEFT JOIN groups_hvac g
        ON g.id=s.group_id
    WHERE s.enabled=1
");

$dayMap = [
    1 => 1, // lundi
    2 => 2,
    3 => 3,
    4 => 4,
    5 => 5,
    6 => 6,
    7 => 7
];

foreach($stmt as $row){
    $title="";
    if($row["group_name"]){
        $title="Groupe ".$row["group_name"];
    }
    else{
        $title=$row["equipment_name"];
    }
    if($row["action"]){
        $title.=" ".$row["action"];
    }
    if($row["temperature"] !== null){
        $title.=" ".$row["temperature"]."°C";
    }
    if($row["action"]=="ON"){
        $color="#198754";
    }
    elseif($row["action"]=="OFF"){
        $color="#dc3545";
    }
    else{
        $color="#0d6efd";
    }
    /*
    ===========================
       PLANNING UNIQUE
    ===========================
    */
    if (empty($row["repeat_days"])) {
        $date = new DateTime(
            $row["execution_time"],
            new DateTimeZone('Pacific/Noumea')
        );
        $events[] = [
            "id" => $row["id"],
            "title" => $title,
            "start" => $date->format(
                "Y-m-d\TH:i:s"
            ),
            "color" => $color
        ];
        continue;
    }

    /*
    ===========================
       PLANNING REPETITIF
    ===========================
    */
    $repeatDays=array_map(
        "intval",
        explode(",",$row["repeat_days"])
    );

    $start=new DateTime(
        $row["execution_time"],
        new DateTimeZone("UTC")
    );

    /*
       Génération 52 semaines
    */

    for($i=0;$i<364;$i++){
        $date=clone $start;
        $date->modify("+".$i." days");

        /*
          ISO-8601 :
          lundi = 1
          dimanche = 7
        */

        $weekday=(int)$date->format("N");
        if(in_array($weekday,$repeatDays)){
            $noumea = clone $date;
            $noumea->setTimezone(
                new DateTimeZone('Pacific/Noumea')
            );
            $events[] = [
                "id" => $row["id"]."_".$i,
                "schedule_id" => $row["id"],
                "title" => $title,
                "start" => $noumea->format(
                    "Y-m-d\TH:i:s"
                ),
                "color" => $color
            ];
        }
    }
}
header("Content-Type: application/json");
echo json_encode($events);