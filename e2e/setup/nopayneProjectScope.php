<?php
// Test-only (No Payne fork, see NOPAYNE-FORK.md): adds to business A, as seeded by tenants.php, the users and
// projects the per-project access control tests need. Never run this against a real database.
//   php nopayneProjectScope.php '<tenants JSON printed by tenants.php>' ['<JSON this script printed last time>']
//
// Idempotent: rows are found by the ID they had last time, or else by a natural key, and put back to the values
// below, so it undoes what a test did.
//   - "restricted": every permission business A has, plus PROJECTS:VIEW:ONLY_ASSIGNED. Any refusal they get is
//     down to the restriction, not a missing permission.
//   - "client": what a client account would be given (the role No Payne asked for), plus the restriction.
// Both are crew on the "own" project; "restricted" also manages the "managed" project. Neither is on A's
// seeded project or sub-project, which are the ones they must not reach.
$db = new PDO(
    "mysql:host=" . getenv('DB_HOSTNAME') . ";port=" . (getenv('DB_PORT') ?: 3306) . ";dbname=" . getenv('DB_DATABASE'),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$tenants = json_decode($argv[1], true);
$a = $tenants['a'];
$previous = isset($argv[2]) ? json_decode($argv[2], true) : null;
const RESTRICTION = "PROJECTS:VIEW:ONLY_ASSIGNED";
const CLIENT_PERMISSIONS = ["PROJECTS:VIEW", RESTRICTION, "PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN",
    "PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_ALL_BUSINESS_ASSETS", "PROJECTS:PROJECT_CREW:VIEW", "ASSETS:ASSET_TYPES:VIEW"];

function one(string $sql, array $params = []) {
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetch();
}
/** Finds the row (by $rememberedId if it still exists, else by matching $key) or inserts it, then sets $key and $values on it. Returns its ID. */
function upsert(string $table, string $idColumn, array $key, array $values = [], ?int $rememberedId = null): int {
    global $db;
    $row = $rememberedId ? one("SELECT `$idColumn` FROM `$table` WHERE `$idColumn` = ?", [$rememberedId]) : false;
    if ($row) $values = $key + $values;
    else {
        $where = implode(" AND ", array_map(fn($column) => "`$column` <=> ?", array_keys($key)));
        $row = one("SELECT `$idColumn` FROM `$table` WHERE $where ORDER BY `$idColumn` LIMIT 1", array_values($key));
    }
    if (!$row) {
        $row = $key + $values;
        $db->prepare("INSERT INTO `$table` (" . implode(",", array_map(fn($c) => "`$c`", array_keys($row))) . ") VALUES (" . implode(",", array_fill(0, count($row), "?")) . ")")
            ->execute(array_values($row));
        return (int) $db->lastInsertId();
    }
    if ($values) {
        $db->prepare("UPDATE `$table` SET " . implode(",", array_map(fn($c) => "`$c` = ?", array_keys($values))) . " WHERE `$idColumn` = ?")
            ->execute([...array_values($values), $row[$idColumn]]);
    }
    return (int) $row[$idColumn];
}

$fullActions = one("SELECT instancePositions_actions FROM instancePositions WHERE instancePositions_id = ?", [$a['positions']['full']])['instancePositions_actions'];
$positions = [
    "restricted" => upsert("instancePositions", "instancePositions_id", ["instances_id" => $a['instanceId'], "instancePositions_displayName" => "E2E nopayne restricted"], [
        "instancePositions_rank" => 3, "instancePositions_deleted" => 0, "instancePositions_actions" => $fullActions . "," . RESTRICTION, "cmsPages_id" => null,
    ]),
    "client" => upsert("instancePositions", "instancePositions_id", ["instances_id" => $a['instanceId'], "instancePositions_displayName" => "E2E nopayne client"], [
        "instancePositions_rank" => 4, "instancePositions_deleted" => 0, "instancePositions_actions" => implode(",", CLIENT_PERMISSIONS), "cmsPages_id" => null,
    ]),
];

// Users are copies of A's limited user (same password, "password!", and the same dev-site server position)
$template = one("SELECT * FROM users WHERE users_userid = ?", [$a['users']['limited']['id']]);
$devPosition = one("SELECT * FROM userPositions WHERE users_userid = ? ORDER BY userPositions_id LIMIT 1", [$a['users']['limited']['id']]);
$users = [];
foreach ($positions as $kind => $position) {
    $email = "e2e_nopayne_$kind@example.com";
    $values = $template;
    unset($values['users_userid'], $values['users_email']);
    $values = array_merge($values, ["users_username" => "e2e_nopayne_$kind", "users_name1" => ucfirst($kind), "users_name2" => "E2E nopayne user",
        "users_deleted" => 0, "users_suspended" => 0, "users_changepass" => 0, "users_selectedInstanceIDLast" => null]);
    $id = upsert("users", "users_userid", ["users_email" => $email], $values);
    $db->prepare("DELETE FROM loginAttempts WHERE loginAttempts_textEntered = ?")->execute([$email]);
    $db->prepare("DELETE FROM userPositions WHERE users_userid = ?")->execute([$id]);
    $userPosition = $devPosition;
    unset($userPosition['userPositions_id']);
    $userPosition['users_userid'] = $id;
    $db->prepare("INSERT INTO userPositions (" . implode(",", array_map(fn($c) => "`$c`", array_keys($userPosition))) . ") VALUES (" . implode(",", array_fill(0, count($userPosition), "?")) . ")")
        ->execute(array_values($userPosition));
    $db->prepare("UPDATE userInstances SET userInstances_deleted = 1 WHERE users_userid = ? AND instancePositions_id != ?")->execute([$id, $position]);
    upsert("userInstances", "userInstances_id", ["users_userid" => $id, "instancePositions_id" => $position], [
        "userInstances_deleted" => 0, "userInstances_archived" => null, "userInstances_extraPermissions" => null, "userInstances_label" => "E2E nopayne $kind",
    ]);
    $users[$kind] = ["id" => $id, "email" => $email];
}

// Copies A's seeded project (client, type, status...) with its own name and dates
$base = one("SELECT * FROM projects WHERE projects_id = ?", [$a['projectId']]);
unset($base['projects_id']);
$projects = [];
foreach (["own" => $a['users']['full']['id'], "managed" => $users['restricted']['id']] as $kind => $manager) {
    $values = array_merge($base, ["projects_name" => "nopayne $kind project", "projects_manager" => $manager, "projects_deleted" => 0, "projects_archived" => 0,
        "projects_parent_project_id" => null, "projects_dates_deliver_start" => "2032-03-01 09:00:00", "projects_dates_deliver_end" => "2032-03-05 18:00:00",
        "projects_dates_use_start" => "2032-03-02 09:00:00", "projects_dates_use_end" => "2032-03-04 18:00:00"]);
    unset($values['projects_description']);
    $projects[$kind] = upsert("projects", "projects_id", ["instances_id" => $a['instanceId'], "projects_description" => "nopayne $kind project description"], $values, $previous['projects'][$kind] ?? null);
    upsert("projectsFinanceCache", "projectsFinanceCache_id", ["projects_id" => $projects[$kind]], [
        "projectsFinanceCache_timestamp" => "2024-01-01 00:00:00", "projectsFinanceCache_timestampUpdated" => null,
        "projectsFinanceCache_equipmentSubTotal" => 0, "projectsFinanceCache_equiptmentDiscounts" => 0, "projectsFinanceCache_equiptmentTotal" => 0,
        "projectsFinanceCache_salesTotal" => 0, "projectsFinanceCache_staffTotal" => 0, "projectsFinanceCache_externalHiresTotal" => 0,
        "projectsFinanceCache_paymentsReceived" => 0, "projectsFinanceCache_grandTotal" => 0, "projectsFinanceCache_value" => 0, "projectsFinanceCache_mass" => 0,
    ]);
    // Assets tests put on the project come off again
    $db->prepare("UPDATE assetsAssignments SET assetsAssignments_deleted = 1 WHERE projects_id = ?")->execute([$projects[$kind]]);
}
// Projects tests created that these users manage (e.g. a sub-project made by new.php) would let them see more
$db->prepare("UPDATE projects SET projects_deleted = 1 WHERE instances_id = ? AND projects_manager IN (?, ?) AND projects_id NOT IN (?, ?)")
    ->execute([$a['instanceId'], $users['restricted']['id'], $users['client']['id'], $projects['own'], $projects['managed']]);
// Crew: both users on "own"; no-one from these tests on anything else
foreach ($users as $kind => $user) {
    $db->prepare("UPDATE crewAssignments SET crewAssignments_deleted = 1 WHERE users_userid = ? AND projects_id != ?")->execute([$user['id'], $projects['own']]);
    upsert("crewAssignments", "crewAssignments_id", ["projects_id" => $projects['own'], "users_userid" => $user['id']], [
        "crewAssignments_role" => "nopayne $kind crew", "crewAssignments_comment" => null, "crewAssignments_deleted" => 0, "crewAssignments_personName" => null, "crewAssignments_rank" => 1,
    ]);
}

echo json_encode(["users" => $users, "positions" => $positions, "projects" => $projects], JSON_PRETTY_PRINT) . "\n";
