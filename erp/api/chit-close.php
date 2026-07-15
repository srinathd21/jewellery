<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

function respond($success,$message,$extra=[],$status=200){
    http_response_code($status);
    echo json_encode(array_merge([
        'success'=>$success,
        'message'=>$message
    ],$extra));
    exit;
}

foreach([
 dirname(__DIR__).'/config/config.php',
 dirname(__DIR__).'/config.php',
 dirname(__DIR__).'/includes/config.php'
] as $file){
    if(is_file($file)){
        require_once $file;
        break;
    }
}

if(!isset($conn) || !($conn instanceof mysqli)){
    respond(false,'Database configuration is not available',[],500);
}

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??0);

$action=strtolower($_REQUEST['action']??'');

if($action==='groups'){

    $stmt=$conn->prepare(
        "SELECT id,group_no,group_name,chit_type,total_members,total_months,status
         FROM chit_groups
         WHERE business_id=? AND branch_id=? AND status='Active'
         ORDER BY group_name"
    );

    $stmt->bind_param("ii",$businessId,$branchId);
    $stmt->execute();

    $rows=[];
    $result=$stmt->get_result();

    while($r=$result->fetch_assoc()){
        $rows[]=$r;
    }

    respond(true,'Groups loaded',['groups'=>$rows]);
}


if($action==='details'){

    $groupId=(int)($_GET['group_id']??0);

    $stmt=$conn->prepare(
        "SELECT *
         FROM chit_groups
         WHERE id=? AND business_id=? AND branch_id=?
         LIMIT 1"
    );

    $stmt->bind_param("iii",$groupId,$businessId,$branchId);
    $stmt->execute();

    $group=$stmt->get_result()->fetch_assoc();

    if(!$group){
        respond(false,'Group not found',[],404);
    }

    $stmt=$conn->prepare(
        "SELECT *
         FROM chit_members
         WHERE chit_group_id=? AND business_id=?"
    );

    $stmt->bind_param("ii",$groupId,$businessId);
    $stmt->execute();

    $members=[];
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $members[]=$row;
    }

    respond(true,'Details loaded',[
        'group'=>$group,
        'members'=>$members
    ]);
}

respond(false,'Invalid action',[],400);
?>