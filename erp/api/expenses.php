<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success'=>$success,'message'=>$message],$extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$rootDir = dirname(__DIR__);
$configCandidates = [
    $rootDir . '/config/config.php',
    $rootDir . '/config.php',
    $rootDir . '/includes/config.php',
    $rootDir . '/super-admin/includes/config.php',
];

$configLoaded = false;
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    respond(false,'Database configuration is not available.',[],500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn,string $table): bool
{
    $safe=$conn->real_escape_string($table);
    $result=$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows>0;
}

if (!function_exists('canAccessExpenses')) {
    function canAccessExpenses(mysqli $conn, string $action = 'open'): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'create' => 'can_create',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? 'can_open';

        $permissionCodes = [
            'perm.expenses',
            'perm.accounts.expenses',
            'perm.accounts',
        ];

        $sessionPermissions = $_SESSION['permissions'] ?? [];

        foreach ($permissionCodes as $code) {
            if (isset($sessionPermissions[$code][$field])) {
                return (int)$sessionPermissions[$code][$field] === 1;
            }
        }

        $allowedRoles = [
            'super admin',
            'super_admin',
            'admin',
            'manager',
            'billing',
            'accounts',
            'accountant',
        ];

        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

        if (
            in_array($roleName, $allowedRoles, true) ||
            in_array($roleCode, $allowedRoles, true)
        ) {
            return true;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);

        /*
         * Fallback 1: resolve role directly from users and roles.
         * This fixes sessions where role_name/role_code were not stored.
         */
        if (
            $userId > 0 &&
            tableExists($conn, 'users') &&
            tableExists($conn, 'roles')
        ) {
            $stmt = $conn->prepare(
                "SELECT LOWER(TRIM(r.role_name)) AS role_name
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    isset($row['role_name']) &&
                    in_array((string)$row['role_name'], $allowedRoles, true)
                ) {
                    return true;
                }
            }
        }

        /*
         * Fallback 2: check role_permissions when available.
         */
        if (
            $businessId > 0 &&
            $roleId > 0 &&
            tableExists($conn, 'permissions') &&
            tableExists($conn, 'role_permissions')
        ) {
            $placeholders = implode(
                ',',
                array_fill(0, count($permissionCodes), '?')
            );

            $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
                    FROM role_permissions rp
                    INNER JOIN permissions p ON p.id = rp.permission_id
                    WHERE rp.business_id = ?
                      AND rp.role_id = ?
                      AND p.is_active = 1
                      AND p.permission_code IN ({$placeholders})";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $types = 'ii' . str_repeat('s', count($permissionCodes));
                $params = array_merge(
                    [$businessId, $roleId],
                    $permissionCodes
                );

                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                return (int)($row['allowed'] ?? 0) === 1;
            }
        }

        return false;
    }
}

function hasColumn(mysqli $conn,string $table,string $column): bool
{
    $table=$conn->real_escape_string($table);
    $column=$conn->real_escape_string($column);
    $result=$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows>0;
}

function validDate(string $date): bool
{
    $object=DateTime::createFromFormat('Y-m-d',$date);
    return $object && $object->format('Y-m-d')===$date;
}

function generateExpenseNo(
    mysqli $conn,
    int $businessId,
    int $branchId,
    bool $hasBranchId
): string {
    $prefix='EXP'.date('Ymd');
    $like=$prefix.'%';
    $lastNo='';

    $sql="SELECT expense_no
          FROM expenses
          WHERE business_id=?
            AND expense_no LIKE ?";

    $types='is';
    $params=[$businessId,$like];

    if($hasBranchId){
        $sql.=" AND branch_id=?";
        $types.='i';
        $params[]=$branchId;
    }

    $sql.=" ORDER BY id DESC LIMIT 1";
    $stmt=$conn->prepare($sql);

    if($stmt){
        $stmt->bind_param($types,...$params);
        $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $lastNo=(string)($row['expense_no']??'');
    }

    $running=1;

    if($lastNo!==''&&preg_match('/(\d{4})$/',$lastNo,$match)){
        $running=(int)$match[1]+1;
    }

    return $prefix.str_pad((string)$running,4,'0',STR_PAD_LEFT);
}

function ensureDefaultExpenseCategory(
    mysqli $conn,
    int $businessId
): int {
    if (
        !tableExists($conn,'expense_categories') ||
        !hasColumn($conn,'expense_categories','id') ||
        !hasColumn($conn,'expense_categories','business_id') ||
        !hasColumn($conn,'expense_categories','category_name')
    ) {
        return 0;
    }

    $activeCondition='';

    if(hasColumn($conn,'expense_categories','is_active')){
        $activeCondition=' AND is_active=1';
    }elseif(hasColumn($conn,'expense_categories','status')){
        $activeCondition=" AND (status=1 OR status='Active')";
    }

    $sql="SELECT id
          FROM expense_categories
          WHERE business_id=?{$activeCondition}
          ORDER BY id ASC
          LIMIT 1";

    $stmt=$conn->prepare($sql);

    if($stmt){
        $stmt->bind_param('i',$businessId);
        $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($row){
            return (int)$row['id'];
        }
    }

    /*
     * No active category exists for this business.
     * Create one valid master record so expenses can satisfy the FK.
     */
    $fields=['business_id','category_name'];
    $placeholders=['?','?'];
    $types='is';
    $values=[$businessId,'General Expense'];

    if(hasColumn($conn,'expense_categories','category_code')){
        $fields[]='category_code';
        $placeholders[]='?';
        $types.='s';
        $values[]='GENERAL';
    }

    if(hasColumn($conn,'expense_categories','is_active')){
        $fields[]='is_active';
        $placeholders[]='?';
        $types.='i';
        $values[]=1;
    }elseif(hasColumn($conn,'expense_categories','status')){
        $fields[]='status';
        $placeholders[]='?';

        $statusMeta=$conn->query(
            "SHOW COLUMNS FROM expense_categories LIKE 'status'"
        );

        $statusRow=$statusMeta?$statusMeta->fetch_assoc():null;
        $statusType=strtolower((string)($statusRow['Type']??''));

        if(
            str_contains($statusType,'char') ||
            str_contains($statusType,'text') ||
            str_contains($statusType,'enum')
        ){
            $types.='s';
            $values[]='Active';
        }else{
            $types.='i';
            $values[]=1;
        }
    }

    if(hasColumn($conn,'expense_categories','created_by')){
        $fields[]='created_by';
        $placeholders[]='?';
        $types.='i';
        $values[]=(int)($_SESSION['user_id']??0);
    }

    if(hasColumn($conn,'expense_categories','created_at')){
        $fields[]='created_at';
        $placeholders[]='NOW()';
    }

    $quotedFields=array_map(
        static fn(string $field): string => "`{$field}`",
        $fields
    );

    $insertSql="INSERT INTO expense_categories (".
        implode(',',$quotedFields).
        ") VALUES (".
        implode(',',$placeholders).
        ")";

    $stmt=$conn->prepare($insertSql);

    if(!$stmt){
        return 0;
    }

    $bind=[$types];

    foreach($values as $key=>$value){
        $bind[]=&$values[$key];
    }

    call_user_func_array([$stmt,'bind_param'],$bind);

    if(!$stmt->execute()){
        /*
         * Another request may have created the same category first.
         * Re-read the first active category instead of failing.
         */
        $stmt->close();

        $stmt=$conn->prepare($sql);

        if($stmt){
            $stmt->bind_param('i',$businessId);
            $stmt->execute();
            $row=$stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int)($row['id']??0);
        }

        return 0;
    }

    $categoryId=(int)$stmt->insert_id;
    $stmt->close();

    return $categoryId;
}

function addAuditLog(mysqli $conn,int $businessId,int $userId,string $action,int $referenceId,string $description): void
{
    if(!tableExists($conn,'audit_logs'))return;

    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $agent=(string)($_SERVER['HTTP_USER_AGENT']??'');

    if(hasColumn($conn,'audit_logs','module_code')){
        $branchId=(int)($_SESSION['branch_id']??0);
        $stmt=$conn->prepare(
            "INSERT INTO audit_logs
            (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,ip_address,user_agent)
            VALUES (?,?,?,'expenses',?,'expenses',?,?,?,?)"
        );
        if($stmt){
            $stmt->bind_param('iiisisss',$businessId,$branchId,$userId,$action,$referenceId,$description,$ip,$agent);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    if(hasColumn($conn,'audit_logs','module_name')){
        $stmt=$conn->prepare(
            "INSERT INTO audit_logs
            (business_id,user_id,module_name,action_type,reference_id,description,ip_address,user_agent,created_at)
            VALUES (?,?,'Expenses',?,?,?,?,?,NOW())"
        );
        if($stmt){
            $stmt->bind_param('iisisss',$businessId,$userId,$action,$referenceId,$description,$ip,$agent);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if(empty($_SESSION['user_id'])){
    respond(false,'Your session has expired. Please log in again.',[],401);
}

$userId=(int)$_SESSION['user_id'];
$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??$_SESSION['default_branch_id']??0);

if($businessId<=0||$branchId<=0){
    respond(false,'A valid business and branch must be selected.',[],403);
}

if (!canAccessExpenses($conn, 'open')) {
    respond(
        false,
        'Access denied. You do not have permission to open Expenses.',
        [],
        403
    );
}

if(!tableExists($conn,'expenses')){
    respond(false,'Required table `expenses` was not found.',[],500);
}

$paymentMethodExists=tableExists($conn,'payment_methods');
$paymentIdColumn=$paymentMethodExists
    ? (hasColumn($conn,'payment_methods','payment_method_id')?'payment_method_id':(hasColumn($conn,'payment_methods','id')?'id':''))
    : '';
$paymentNameColumn=$paymentMethodExists
    ? (hasColumn($conn,'payment_methods','payment_method_name')?'payment_method_name':(hasColumn($conn,'payment_methods','method_name')?'method_name':''))
    : '';
$paymentStatusColumn=$paymentMethodExists
    ? (hasColumn($conn,'payment_methods','status')?'status':(hasColumn($conn,'payment_methods','is_active')?'is_active':''))
    : '';

/*
 * The current jewellery database stores categories in expense_categories and
 * expenses.expense_category_id is a required foreign key.
 */
$expenseCategoryTableExists = tableExists($conn,'expense_categories');
$expenseCategoryIdColumn = hasColumn($conn,'expenses','expense_category_id')
    ? 'expense_category_id'
    : '';

if (!$expenseCategoryTableExists || $expenseCategoryIdColumn === '') {
    respond(
        false,
        'Expense category configuration is missing from the database.',
        [],
        500
    );
}

$expenseReferenceColumn = hasColumn($conn,'expenses','reference_no')
    ? 'reference_no'
    : (hasColumn($conn,'expenses','bill_no') ? 'bill_no' : '');

$expensePaidToColumn = hasColumn($conn,'expenses','payee_name')
    ? 'payee_name'
    : (hasColumn($conn,'expenses','paid_to') ? 'paid_to' : '');

$expenseHasBranchId = hasColumn($conn,'expenses','branch_id');
$expenseHasUpdatedAt = hasColumn($conn,'expenses','updated_at');
$expenseHasExpenseNo = hasColumn($conn,'expenses','expense_no');
$expenseHasWorkflowStatus = hasColumn($conn,'expenses','workflow_status');


function createOrFindExpenseCategory(
    mysqli $conn,
    int $businessId,
    int $userId,
    string $categoryName
): array {
    $categoryName=trim($categoryName);

    if($categoryName===''){
        throw new RuntimeException('Category name is required.');
    }

    if(mb_strlen($categoryName)>120){
        throw new RuntimeException('Category name must not exceed 120 characters.');
    }

    $sql="SELECT id,category_name,category_code
          FROM expense_categories
          WHERE business_id=?
            AND LOWER(TRIM(category_name))=LOWER(TRIM(?))
          LIMIT 1";

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        throw new RuntimeException('Unable to check expense category: '.$conn->error);
    }

    $stmt->bind_param('is',$businessId,$categoryName);
    $stmt->execute();
    $existing=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if($existing){
        if(hasColumn($conn,'expense_categories','is_active')){
            $stmt=$conn->prepare(
                "UPDATE expense_categories
                 SET is_active=1
                 WHERE id=? AND business_id=?"
            );
            if($stmt){
                $id=(int)$existing['id'];
                $stmt->bind_param('ii',$id,$businessId);
                $stmt->execute();
                $stmt->close();
            }
        }

        return [
            'id'=>(int)$existing['id'],
            'category_name'=>(string)$existing['category_name'],
            'category_code'=>(string)($existing['category_code']??''),
        ];
    }

    $baseCode=strtoupper(
        preg_replace('/[^A-Za-z0-9]+/','_',trim($categoryName))
    );
    $baseCode=trim($baseCode,'_');
    if($baseCode==='')$baseCode='CATEGORY';
    $baseCode=mb_substr($baseCode,0,40);

    $categoryCode=$baseCode;
    $counter=1;

    while(true){
        $stmt=$conn->prepare(
            "SELECT id
             FROM expense_categories
             WHERE business_id=? AND category_code=?
             LIMIT 1"
        );

        if(!$stmt){
            throw new RuntimeException('Unable to validate category code: '.$conn->error);
        }

        $stmt->bind_param('is',$businessId,$categoryCode);
        $stmt->execute();
        $duplicate=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$duplicate)break;

        $counter++;
        $categoryCode=mb_substr($baseCode,0,34).'_'.$counter;
    }

    $fields=['business_id','category_name'];
    $placeholders=['?','?'];
    $types='is';
    $values=[$businessId,$categoryName];

    if(hasColumn($conn,'expense_categories','category_code')){
        $fields[]='category_code';
        $placeholders[]='?';
        $types.='s';
        $values[]=$categoryCode;
    }

    if(hasColumn($conn,'expense_categories','is_active')){
        $fields[]='is_active';
        $placeholders[]='?';
        $types.='i';
        $values[]=1;
    }elseif(hasColumn($conn,'expense_categories','status')){
        $fields[]='status';
        $placeholders[]='?';
        $types.='s';
        $values[]='Active';
    }

    if(hasColumn($conn,'expense_categories','created_by')){
        $fields[]='created_by';
        $placeholders[]='?';
        $types.='i';
        $values[]=$userId;
    }

    if(hasColumn($conn,'expense_categories','created_at')){
        $fields[]='created_at';
        $placeholders[]='NOW()';
    }

    $quoted=array_map(
        static fn(string $field): string => "`{$field}`",
        $fields
    );

    $sql="INSERT INTO expense_categories (".
        implode(',',$quoted).
        ") VALUES (".
        implode(',',$placeholders).
        ")";

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        throw new RuntimeException('Unable to prepare expense category insert: '.$conn->error);
    }

    $bind=[$types];
    foreach($values as $key=>$value){
        $bind[]=&$values[$key];
    }
    call_user_func_array([$stmt,'bind_param'],$bind);

    if(!$stmt->execute()){
        $error=$stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to create expense category: '.$error);
    }

    $id=(int)$stmt->insert_id;
    $stmt->close();

    return [
        'id'=>$id,
        'category_name'=>$categoryName,
        'category_code'=>$categoryCode,
    ];
}

$action=strtolower(trim((string)($_REQUEST['action']??'list')));

if($action==='bootstrap'){
    $methods=[];

    if($paymentMethodExists && $paymentIdColumn!=='' && $paymentNameColumn!==''){
        $sql="SELECT `{$paymentIdColumn}` AS id,`{$paymentNameColumn}` AS method_name
              FROM payment_methods WHERE 1=1";
        $types='';
        $params=[];

        if(hasColumn($conn,'payment_methods','business_id')){
            $sql.=" AND (business_id=? OR business_id IS NULL)";
            $types.='i';
            $params[]=$businessId;
        }

        if($paymentStatusColumn!==''){
            $sql.=" AND COALESCE(`{$paymentStatusColumn}`,1)=1";
        }

        $sql.=" ORDER BY `{$paymentNameColumn}` ASC";
        $stmt=$conn->prepare($sql);

        if($stmt){
            if($params)$stmt->bind_param($types,...$params);
            $stmt->execute();
            $result=$stmt->get_result();
            while($result&&$row=$result->fetch_assoc()){
                $row['id']=(int)$row['id'];
                $methods[]=$row;
            }
            $stmt->close();
        }

        if(!$methods){
            $sql="SELECT `{$paymentIdColumn}` AS id,`{$paymentNameColumn}` AS method_name
                  FROM payment_methods WHERE 1=1";
            if($paymentStatusColumn!==''){
                $sql.=" AND COALESCE(`{$paymentStatusColumn}`,1)=1";
            }
            $sql.=" ORDER BY `{$paymentNameColumn}` ASC";
            $result=$conn->query($sql);
            while($result&&$row=$result->fetch_assoc()){
                $row['id']=(int)$row['id'];
                $methods[]=$row;
            }
        }
    }

    $categories=[];

    $categorySql="SELECT id,category_name,category_code
                  FROM expense_categories
                  WHERE business_id=?";

    if(hasColumn($conn,'expense_categories','is_active')){
        $categorySql.=" AND is_active=1";
    }elseif(hasColumn($conn,'expense_categories','status')){
        $categorySql.=" AND (status=1 OR status='Active')";
    }

    $categorySql.=" ORDER BY category_name ASC";
    $stmt=$conn->prepare($categorySql);

    if(!$stmt){
        respond(
            false,
            'Unable to prepare expense categories: '.$conn->error,
            [],
            500
        );
    }

    $stmt->bind_param('i',$businessId);
    $stmt->execute();
    $result=$stmt->get_result();

    while($result&&$row=$result->fetch_assoc()){
        $categories[]=[
            'id'=>(int)$row['id'],
            'category_name'=>(string)$row['category_name'],
            'category_code'=>(string)($row['category_code']??''),
        ];
    }

    $stmt->close();

    respond(true,'Expense data loaded successfully.',[
        'payment_methods'=>$methods,
        'categories'=>$categories,
    ]);
}

if($action==='list'){
    $search=trim((string)($_GET['search']??''));
    $fromDate=trim((string)($_GET['from_date']??''));
    $toDate=trim((string)($_GET['to_date']??''));
    $categoryId=(int)($_GET['category']??0);

    $methodSelect=($paymentMethodExists&&$paymentIdColumn!==''&&$paymentNameColumn!=='')
        ?"pm.`{$paymentNameColumn}` AS method_name"
        :"'' AS method_name";

    $methodJoin=($paymentMethodExists&&$paymentIdColumn!==''&&$paymentNameColumn!=='')
        ?"LEFT JOIN payment_methods pm
             ON pm.`{$paymentIdColumn}`=e.payment_method_id"
        :"";

    $referenceSelect=$expenseReferenceColumn!==''
        ?"e.`{$expenseReferenceColumn}` AS reference_no"
        :"'' AS reference_no";

    $where=['e.business_id=?'];
    $types='i';
    $params=[$businessId];

    if($expenseHasBranchId){
        $where[]='e.branch_id=?';
        $types.='i';
        $params[]=$branchId;
    }

    if($search!==''){
        $parts=[
            'ec.category_name LIKE ?',
            'ec.category_code LIKE ?',
            'e.description LIKE ?',
        ];

        $like='%'.$search.'%';
        $params[]=$like;
        $params[]=$like;
        $params[]=$like;
        $types.='sss';

        if($expenseReferenceColumn!==''){
            $parts[]="e.`{$expenseReferenceColumn}` LIKE ?";
            $params[]=$like;
            $types.='s';
        }

        if($expensePaidToColumn!==''){
            $parts[]="e.`{$expensePaidToColumn}` LIKE ?";
            $params[]=$like;
            $types.='s';
        }

        if($methodJoin!==''){
            $parts[]="pm.`{$paymentNameColumn}` LIKE ?";
            $params[]=$like;
            $types.='s';
        }

        $where[]='('.implode(' OR ',$parts).')';
    }

    if($fromDate!==''){
        if(!validDate($fromDate)){
            respond(false,'Invalid from date.',[],422);
        }

        $where[]='e.expense_date>=?';
        $params[]=$fromDate;
        $types.='s';
    }

    if($toDate!==''){
        if(!validDate($toDate)){
            respond(false,'Invalid to date.',[],422);
        }

        $where[]='e.expense_date<=?';
        $params[]=$toDate;
        $types.='s';
    }

    if($categoryId>0){
        $where[]='e.expense_category_id=?';
        $params[]=$categoryId;
        $types.='i';
    }

    $whereSql=implode(' AND ',$where);
    $categoryJoin="INNER JOIN expense_categories ec
                       ON ec.id=e.expense_category_id
                      AND ec.business_id=e.business_id";

    $summarySql="SELECT
                    COUNT(*) AS total_count,
                    COALESCE(SUM(e.amount),0) AS total_amount
                 FROM expenses e
                 {$categoryJoin}
                 {$methodJoin}
                 WHERE {$whereSql}";

    $stmt=$conn->prepare($summarySql);

    if(!$stmt){
        respond(
            false,
            'Unable to prepare expense summary: '.$conn->error,
            [],
            500
        );
    }

    $stmt->bind_param($types,...$params);
    $stmt->execute();
    $summary=$stmt->get_result()->fetch_assoc()?:[];
    $stmt->close();

    $listSql="SELECT
                    e.*,
                    ec.category_name AS expense_category,
                    ec.category_code,
                    {$referenceSelect},
                    {$methodSelect}
              FROM expenses e
              {$categoryJoin}
              {$methodJoin}
              WHERE {$whereSql}
              ORDER BY e.id DESC";

    $stmt=$conn->prepare($listSql);

    if(!$stmt){
        respond(
            false,
            'Unable to prepare expense list: '.$conn->error,
            [],
            500
        );
    }

    $stmt->bind_param($types,...$params);
    $stmt->execute();
    $result=$stmt->get_result();
    $rows=[];

    while($result&&$row=$result->fetch_assoc()){
        $row['id']=(int)$row['id'];
        $row['expense_category_id']=(int)$row['expense_category_id'];
        $row['amount']=(float)$row['amount'];
        $row['expense_date_display']=!empty($row['expense_date'])
            ? date('d-m-Y',strtotime($row['expense_date']))
            : '—';
        $row['created_at_display']=!empty($row['created_at'])
            ? date('d-m-Y h:i A',strtotime($row['created_at']))
            : '—';
        $rows[]=$row;
    }

    $stmt->close();

    respond(true,'Expenses loaded successfully.',[
        'rows'=>$rows,
        'total_count'=>(int)($summary['total_count']??0),
        'total_amount'=>(float)($summary['total_amount']??0),
    ]);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    respond(false,'Invalid request method.',[],405);
}

$csrfToken=(string)($_POST['csrf_token']??'');
$sessionToken=(string)($_SESSION['expenses_csrf']??'');

if($csrfToken===''||$sessionToken===''||!hash_equals($sessionToken,$csrfToken)){
    respond(false,'Invalid security token. Refresh the page and try again.',[],419);
}

if($action==='create_category'){
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        respond(false,'Invalid request method.',[],405);
    }

    if(!canAccessExpenses($conn,'create')){
        respond(
            false,
            'You do not have permission to create expense categories.',
            [],
            403
        );
    }

    $categoryName=trim((string)($_POST['category_name']??''));

    try{
        $category=createOrFindExpenseCategory(
            $conn,
            $businessId,
            $userId,
            $categoryName
        );

        respond(true,'Expense category is ready.',[
            'category'=>$category,
        ]);
    }catch(Throwable $error){
        respond(false,$error->getMessage(),[],422);
    }
}

if($action==='create'){
    if(!canAccessExpenses($conn,'create')){
        respond(
            false,
            'You do not have permission to create expenses.',
            [],
            403
        );
    }

    $expenseDate=trim((string)($_POST['expense_date']??''));
    $expenseCategoryId=(int)($_POST['expense_category_id']??0);
    $expenseCategoryName=trim(
        (string)($_POST['expense_category_name']??'')
    );
    $description=trim((string)($_POST['description']??''));
    $amount=(float)($_POST['amount']??0);
    $paymentMethodId=(int)($_POST['payment_method_id']??0);
    $referenceNo=trim((string)($_POST['reference_no']??''));

    if(!validDate($expenseDate)){
        respond(false,'Please select a valid expense date.',[],422);
    }

    if($expenseCategoryName!==''){
        try{
            /*
             * Always resolve the category currently visible in the input.
             * This deliberately overrides any stale hidden category ID.
             */
            $resolvedCategory=createOrFindExpenseCategory(
                $conn,
                $businessId,
                $userId,
                $expenseCategoryName
            );

            $expenseCategoryId=(int)$resolvedCategory['id'];
            $expenseCategoryName=(string)$resolvedCategory['category_name'];
        }catch(Throwable $error){
            respond(
                false,
                $error->getMessage(),
                [],
                422
            );
        }
    }

    if($expenseCategoryId<=0){
        respond(
            false,
            'Please type or select an expense category.',
            [],
            422
        );
    }

    if($description===''){
        respond(false,'Description is required.',[],422);
    }

    if($amount<=0){
        respond(false,'Amount must be greater than zero.',[],422);
    }

    /*
     * Validate the category in the master table before using its foreign key.
     */
    $categorySql="SELECT id,category_name
                  FROM expense_categories
                  WHERE id=?
                    AND business_id=?";

    if(hasColumn($conn,'expense_categories','is_active')){
        $categorySql.=" AND is_active=1";
    }elseif(hasColumn($conn,'expense_categories','status')){
        $categorySql.=" AND (status=1 OR status='Active')";
    }

    $categorySql.=" LIMIT 1";
    $stmt=$conn->prepare($categorySql);

    if(!$stmt){
        respond(
            false,
            'Unable to validate expense category: '.$conn->error,
            [],
            500
        );
    }

    $stmt->bind_param('ii',$expenseCategoryId,$businessId);
    $stmt->execute();
    $category=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$category){
        respond(
            false,
            'The selected expense category is invalid or inactive.',
            [],
            422
        );
    }

    $paymentMethodValue=null;

    if($paymentMethodId>0){
        if(!$paymentMethodExists||$paymentIdColumn===''){
            respond(
                false,
                'Payment method configuration is not available.',
                [],
                500
            );
        }

        $sql="SELECT `{$paymentIdColumn}` AS id
              FROM payment_methods
              WHERE `{$paymentIdColumn}`=?";

        if($paymentStatusColumn!==''){
            $sql.=" AND COALESCE(`{$paymentStatusColumn}`,1)=1";
        }

        $sql.=" LIMIT 1";
        $stmt=$conn->prepare($sql);

        if(!$stmt){
            respond(
                false,
                'Unable to validate payment method: '.$conn->error,
                [],
                500
            );
        }

        $stmt->bind_param('i',$paymentMethodId);
        $stmt->execute();
        $validMethod=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$validMethod){
            respond(
                false,
                'Selected payment method is invalid or inactive.',
                [],
                422
            );
        }

        $paymentMethodValue=$paymentMethodId;
    }

    $expenseNo=$expenseHasExpenseNo
        ? generateExpenseNo(
            $conn,
            $businessId,
            $branchId,
            $expenseHasBranchId
        )
        : '';

    $fields=[];
    $placeholders=[];
    $bindTypes='';
    $bindValues=[];

    $columnValues=[
        'business_id'=>['i',$businessId],
        'branch_id'=>['i',$branchId],
        'expense_category_id'=>['i',$expenseCategoryId],
        'expense_no'=>['s',$expenseNo],
        'expense_date'=>['s',$expenseDate],
        'description'=>['s',$description],
        'amount'=>['d',$amount],
        'payment_method_id'=>['i',$paymentMethodValue],
        'created_by'=>['i',$userId],
    ];

    if($expenseReferenceColumn!==''){
        $columnValues[$expenseReferenceColumn]=[
            's',
            $referenceNo!==''?$referenceNo:null
        ];
    }

    if($expensePaidToColumn!==''){
        $columnValues[$expensePaidToColumn]=['s',null];
    }

    if($expenseHasWorkflowStatus){
        $columnValues['workflow_status']=['s','Posted'];
    }

    foreach($columnValues as $column=>[$type,$value]){
        if(hasColumn($conn,'expenses',$column)){
            $fields[]="`{$column}`";
            $placeholders[]='?';
            $bindTypes.=$type;
            $bindValues[]=$value;
        }
    }

    if(hasColumn($conn,'expenses','created_at')){
        $fields[]='`created_at`';
        $placeholders[]='NOW()';
    }

    if($expenseHasUpdatedAt){
        $fields[]='`updated_at`';
        $placeholders[]='NOW()';
    }

    $insertSql="INSERT INTO expenses (".
        implode(',',$fields).
        ") VALUES (".
        implode(',',$placeholders).
        ")";

    $stmt=$conn->prepare($insertSql);

    if(!$stmt){
        respond(
            false,
            'Unable to prepare expense insert: '.$conn->error,
            [],
            500
        );
    }

    $bind=[$bindTypes];

    foreach($bindValues as $key=>$value){
        $bind[]=&$bindValues[$key];
    }

    call_user_func_array([$stmt,'bind_param'],$bind);

    if(!$stmt->execute()){
        $error=$stmt->error;
        $stmt->close();

        respond(
            false,
            'Unable to save expense: '.$error,
            [],
            500
        );
    }

    $expenseId=(int)$stmt->insert_id;
    $stmt->close();

    addAuditLog(
        $conn,
        $businessId,
        $userId,
        'Create',
        $expenseId,
        'Created expense '.$category['category_name']
    );

    respond(true,'Expense added successfully.',[
        'expense_id'=>$expenseId,
        'expense_no'=>$expenseNo,
        'category'=>[
            'id'=>$expenseCategoryId,
            'category_name'=>$expenseCategoryName,
        ],
    ]);
}

if($action==='delete'){
    if (!canAccessExpenses($conn, 'delete')) {
        respond(
            false,
            'You do not have permission to delete expenses.',
            [],
            403
        );
    }

    $expenseId=(int)($_POST['expense_id']??0);

    if($expenseId<=0)respond(false,'Invalid expense.',[],422);

    $deleteSql = "SELECT
                        e.id,
                        ec.category_name AS expense_category,
                        e.amount
                  FROM expenses e
                  INNER JOIN expense_categories ec
                    ON ec.id=e.expense_category_id
                   AND ec.business_id=e.business_id
                  WHERE e.id=?
                    AND e.business_id=?";

    $deleteTypes='ii';
    $deleteParams=[$expenseId,$businessId];

    if($expenseHasBranchId){
        $deleteSql.=" AND e.branch_id=?";
        $deleteTypes.='i';
        $deleteParams[]=$branchId;
    }

    $deleteSql.=" LIMIT 1";

    $stmt=$conn->prepare($deleteSql);

    if(!$stmt)respond(false,'Unable to validate expense: '.$conn->error,[],500);
    $stmt->bind_param($deleteTypes,...$deleteParams);
    $stmt->execute();
    $expense=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$expense)respond(false,'Expense was not found.',[],404);

    $deleteSql="DELETE FROM expenses WHERE id=? AND business_id=?";
    $deleteTypes='ii';
    $deleteParams=[$expenseId,$businessId];

    if($expenseHasBranchId){
        $deleteSql.=" AND branch_id=?";
        $deleteTypes.='i';
        $deleteParams[]=$branchId;
    }

    $deleteSql.=" LIMIT 1";

    $stmt=$conn->prepare($deleteSql);
    if(!$stmt)respond(false,'Unable to prepare expense deletion: '.$conn->error,[],500);
    $stmt->bind_param($deleteTypes,...$deleteParams);

    if(!$stmt->execute()){
        $error=$stmt->error;
        $stmt->close();
        respond(false,'Unable to delete expense: '.$error,[],500);
    }

    $stmt->close();

    addAuditLog(
        $conn,
        $businessId,
        $userId,
        'Delete',
        $expenseId,
        'Deleted expense '.($expense['expense_category']??'')
    );

    respond(true,'Expense deleted successfully.');
}

respond(false,'Invalid action.',[],400);
