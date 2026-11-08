<?php
/**
 * No Payne fork: per-project access control. See NOPAYNE-FORK.md at the repo root.
 *
 * A user whose role in a business has PROJECTS:VIEW:ONLY_ASSIGNED ("restricted") can only see and touch the
 * projects in that business they manage (projects_manager) or are crew on (crewAssignments), and every
 * sub-project of those (to any depth). Everyone else, including server admins with
 * INSTANCES:FULL_PERMISSIONS_IN_INSTANCE, is unaffected.
 *
 * All the logic lives here so upstream merges only meet one-line hooks:
 *   - nopayneProjectScopeRequestGuard() runs at the end of headSecure.php / apiHeadSecure.php (and in
 *     api/file/index.php). It finds every project a request refers to - directly (projects_id, ...) or through
 *     a child record (assetsAssignments_id, payments_id, s3files_id, ...) - and refuses the request if one of
 *     them is out of scope.
 *   - nopayneProjectScopeWhere() is called before each query that lists projects, to limit it to the user's.
 *   - nopayneProjectScopeTopLevel() / nopayneProjectScopeTopLevelWhere() list a sub-project whose parent is out
 *     of scope at top level, where stock nests sub-projects under their parents.
 *   - nopayneProjectScopeRedact() blanks the names of other projects in rows that only mention them in passing
 *     (an asset's "assigned to X", a crew clash warning).
 *
 * The lookups use the raw mysqli connection rather than the $DBLIB query builder, so they can run while a
 * caller is half-way through building a query without disturbing it.
 */

const NOPAYNE_PROJECT_SCOPE_PERMISSION = "PROJECTS:VIEW:ONLY_ASSIGNED";

// SQL giving the project a child record belongs to (one ? = the record's ID)
const NOPAYNE_PROJECT_SCOPE_LOOKUPS = [
    "project" => null,
    "assetsAssignments" => "SELECT projects_id FROM assetsAssignments WHERE assetsAssignments_id = ?",
    "payments" => "SELECT projects_id FROM payments WHERE payments_id = ?",
    "crewAssignments" => "SELECT projects_id FROM crewAssignments WHERE crewAssignments_id = ?",
    "projectsNotes" => "SELECT projects_id FROM projectsNotes WHERE projectsNotes_id = ?",
    "projectsVacantRoles" => "SELECT projects_id FROM projectsVacantRoles WHERE projectsVacantRoles_id = ?",
    "projectsVacantRolesApplications" => "SELECT projectsVacantRoles.projects_id FROM projectsVacantRolesApplications JOIN projectsVacantRoles ON projectsVacantRoles.projectsVacantRoles_id = projectsVacantRolesApplications.projectsVacantRoles_id WHERE projectsVacantRolesApplications_id = ?",
    // Project files (7), invoices/quotes/delivery notes (20-22), payment files (14) and vacancy files (18). Other files give NULL.
    "s3files" => "SELECT CASE WHEN s3files_meta_type IN (7, 20, 21, 22) THEN s3files_meta_subType
        WHEN s3files_meta_type = 14 THEN (SELECT projects_id FROM payments WHERE payments_id = s3files_meta_subType)
        WHEN s3files_meta_type = 18 THEN (SELECT projects_id FROM projectsVacantRoles WHERE projectsVacantRoles_id = s3files_meta_subType)
        END FROM s3files WHERE s3files_id = ?",
];

// Request parameters that name a project or one of its records, on any endpoint or page
const NOPAYNE_PROJECT_SCOPE_PARAMS = [
    "projects_id" => "project",
    "projects_parent_project_id" => "project",
    "assetsAssignments_id" => "assetsAssignments",
    "assetsAssignments" => "assetsAssignments",
    "payments_id" => "payments",
    "crewAssignments_id" => "crewAssignments",
    "projectsNotes_id" => "projectsNotes",
    "projectsVacantRoles_id" => "projectsVacantRoles",
    "projectsVacantRolesApplications_id" => "projectsVacantRolesApplications",
    "s3files_id" => "s3files",
];

// Parameters whose meaning depends on the script (path relative to src/)
const NOPAYNE_PROJECT_SCOPE_SCRIPT_PARAMS = [
    "api/projects/data.php" => ["id" => "project"],
    "project/index.php" => ["id" => "project"],
    "project/projectInvoice.php" => ["id" => "project"],
    "project/crew/vacantCrew.php" => ["id" => "project"],
    "project/noteExport.php" => ["id" => "projectsNotes"],
    "project/crew/vacancy.php" => ["id" => "projectsVacantRoles"],
    "project/crew/applications.php" => ["id" => "projectsVacantRoles"],
    "api/s3files/uploadProjectInvoice.php" => ["id" => "project"],
    "assets.php" => ["project" => "project", "project_referer" => "project"],
    "api/file/index.php" => ["f" => "s3files"],
];

// File types (s3files_meta_type) attached to a project or one of its records, for uploads (typeid + subtype)
const NOPAYNE_PROJECT_SCOPE_FILE_TYPES = [7 => "project", 20 => "project", 21 => "project", 22 => "project", 14 => "payments", 18 => "projectsVacantRoles"];

/** Whether the user is restricted in the given business (default: the current one) */
function nopayneProjectScopeRestricted($instanceId = null): bool
{
    global $AUTH;
    if (!isset($AUTH) or !$AUTH->login) return false;
    if ($AUTH->serverPermissionCheck("INSTANCES:FULL_PERMISSIONS_IN_INSTANCE")) return false;
    if ($instanceId === null) {
        if (!$AUTH->data['instance']) return false;
        $instanceId = $AUTH->data['instance']['instances_id'];
    }
    foreach ($AUTH->data['instances'] as $instance) {
        if ($instance['instances_id'] == $instanceId) return in_array(NOPAYNE_PROJECT_SCOPE_PERMISSION, $instance['permissions'] ?? []);
    }
    return false;
}

/** Runs one lookup on the raw connection (doesn't touch $DBLIB's query builder). Returns the first column of every row. */
function nopayneProjectScopeQuery(string $sql, array $params): array
{
    global $DBLIB;
    $statement = $DBLIB->mysqli()->prepare($sql);
    if (!$statement) throw new Exception("Project scope lookup failed");
    if ($params) $statement->bind_param(str_repeat("s", count($params)), ...array_map("strval", $params));
    $statement->execute();
    $result = $statement->get_result();
    $values = [];
    while ($row = $result->fetch_row()) $values[] = $row[0];
    $statement->close();
    return $values;
}

/** IDs of the projects in a business the user manages or is crew on, and all their sub-projects (to any depth) */
function nopayneProjectScopeIds($instanceId = null): array
{
    global $AUTH;
    static $cache = [];
    if ($instanceId === null) $instanceId = $AUTH->data['instance']['instances_id'];
    if (!isset($cache[$instanceId])) {
        $userId = $AUTH->data['users_userid'];
        $cache[$instanceId] = array_map("intval", nopayneProjectScopeQuery(
            "SELECT projects_id FROM projects WHERE instances_id = ? AND projects_deleted = 0 AND (projects_manager = ?
                OR projects_id IN (SELECT projects_id FROM crewAssignments WHERE users_userid = ? AND crewAssignments_deleted = 0))",
            [$instanceId, $userId, $userId]
        ));
        // Add sub-projects a level at a time. Only IDs not seen before are followed, so a cycle of parents ends.
        $level = $cache[$instanceId];
        while ($level) {
            $children = array_map("intval", nopayneProjectScopeQuery(
                "SELECT projects_id FROM projects WHERE instances_id = ? AND projects_deleted = 0 AND projects_parent_project_id IN (" . implode(",", $level) . ")",
                [$instanceId]
            ));
            $level = array_values(array_diff($children, $cache[$instanceId]));
            $cache[$instanceId] = array_merge($cache[$instanceId], $level);
        }
    }
    return $cache[$instanceId];
}

/** Whether the user may see/touch a project. Projects that don't exist are let through for the page's own checks to refuse. */
function nopayneProjectScopeCanAccess($projectId): bool
{
    $instance = nopayneProjectScopeQuery("SELECT instances_id FROM projects WHERE projects_id = ?", [$projectId]);
    if (!$instance or !nopayneProjectScopeRestricted($instance[0])) return true;
    return in_array((int) $projectId, nopayneProjectScopeIds($instance[0]), true);
}

/** Limits the query being built to the user's projects, if they're restricted. $column is the projects_id column to filter on. */
function nopayneProjectScopeWhere(string $column = "projects.projects_id"): void
{
    global $DBLIB;
    if (!nopayneProjectScopeRestricted()) return;
    $DBLIB->where($column, nopayneProjectScopeIds() ?: [0], "IN"); // [0] matches nothing; an empty IN () is a syntax error
}

/**
 * Whether a project should be listed as top level: it has no parent, or (for a restricted user) its parent is
 * out of their scope, so the sub-project they can reach doesn't vanish from lists that nest sub-projects under
 * their parents. As the scope includes every sub-project of a project in it, a parent out of scope means the
 * user reached this project directly.
 */
function nopayneProjectScopeTopLevel($parentId): bool
{
    if ($parentId === null) return true;
    return nopayneProjectScopeRestricted() and !in_array((int) $parentId, nopayneProjectScopeIds(), true);
}

/** In place of stock's where("projects_parent_project_id IS NULL"): top-level projects, as nopayneProjectScopeTopLevel() defines them */
function nopayneProjectScopeTopLevelWhere(string $column = "projects.projects_parent_project_id"): void
{
    global $DBLIB;
    if (!nopayneProjectScopeRestricted()) $DBLIB->where("$column IS NULL");
    else $DBLIB->where("($column IS NULL OR $column NOT IN (" . implode(",", nopayneProjectScopeIds() ?: [0]) . "))");
}

/** Replaces the names of projects out of the user's scope in rows (each with projects_id and projects_name) */
function nopayneProjectScopeRedact(&$rows): void
{
    if (!is_array($rows) or !nopayneProjectScopeRestricted()) return;
    foreach ($rows as &$row) {
        if (isset($row['projects_id']) and !in_array((int) $row['projects_id'], nopayneProjectScopeIds(), true)) $row['projects_name'] = "another project";
    }
}

/** Refuses the request: a 404 page, or the API's usual error */
function nopayneProjectScopeDeny(): void
{
    global $TWIG, $PAGEDATA;
    if (function_exists("finish")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
    http_response_code(404);
    if (isset($PAGEDATA['USERDATA'])) die($TWIG->render('404.twig', $PAGEDATA));
    die("404");
}

/** Every scalar in a request value (which may be an array of IDs) that could name a record */
function nopayneProjectScopeValues($value): array
{
    if (is_array($value)) return array_merge([], ...array_map("nopayneProjectScopeValues", array_values($value)));
    if (is_object($value)) return nopayneProjectScopeValues((array) $value);
    // MySQL compares "12abc" = 12 as 12, so check what it would match
    return (is_scalar($value) and (int) $value > 0) ? [(int) $value] : [];
}

/** The project IDs a request refers to */
function nopayneProjectScopeRequestProjects(): array
{
    // Fields can come in $_GET, $_POST or as {name, value} pairs in formData (which data.php copies into $_GET)
    $fields = [];
    foreach ([$_GET, $_POST] as $source) {
        foreach ($source as $name => $value) $fields[] = [$name, $value];
        if (isset($source['formData']) and is_array($source['formData'])) {
            foreach ($source['formData'] as $item) {
                if (is_array($item) and isset($item['name'], $item['value']) and is_string($item['name'])) $fields[] = [$item['name'], $item['value']];
            }
        }
    }
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? "");
    $root = realpath(__DIR__ . "/../../..") . DIRECTORY_SEPARATOR;
    $script = ($script and str_starts_with($script, $root)) ? str_replace(DIRECTORY_SEPARATOR, "/", substr($script, strlen($root))) : "";
    $params = NOPAYNE_PROJECT_SCOPE_PARAMS + (NOPAYNE_PROJECT_SCOPE_SCRIPT_PARAMS[$script] ?? []);

    $projects = [];
    foreach ($fields as [$name, $value]) {
        if (!isset($params[$name])) continue;
        foreach (nopayneProjectScopeValues($value) as $id) {
            $sql = NOPAYNE_PROJECT_SCOPE_LOOKUPS[$params[$name]];
            if ($sql === null) $projects[] = $id;
            else $projects = array_merge($projects, nopayneProjectScopeQuery($sql, [$id]));
        }
    }
    // File uploads say what they're attached to with typeid + subtype
    foreach ([$_GET, $_POST] as $source) {
        if (!isset($source['typeid'], $source['subtype']) or !is_scalar($source['typeid'])) continue;
        $type = NOPAYNE_PROJECT_SCOPE_FILE_TYPES[(int) $source['typeid']] ?? false;
        if (!$type) continue;
        foreach (nopayneProjectScopeValues($source['subtype']) as $id) {
            $sql = NOPAYNE_PROJECT_SCOPE_LOOKUPS[$type];
            if ($sql === null) $projects[] = $id;
            else $projects = array_merge($projects, nopayneProjectScopeQuery($sql, [$id]));
        }
    }
    return array_unique(array_filter(array_map("intval", $projects)));
}

/**
 * Refuses the request if it refers to a project the user can't access. Cheap for everyone else: nothing is
 * looked up unless the user is restricted in at least one of their businesses.
 */
function nopayneProjectScopeRequestGuard(): void
{
    global $AUTH;
    if (!isset($AUTH) or !$AUTH->login) return;
    $restrictedSomewhere = false;
    foreach ($AUTH->data['instances'] as $instance) {
        if (in_array(NOPAYNE_PROJECT_SCOPE_PERMISSION, $instance['permissions'] ?? [])) $restrictedSomewhere = true;
    }
    if (!$restrictedSomewhere or $AUTH->serverPermissionCheck("INSTANCES:FULL_PERMISSIONS_IN_INSTANCE")) return;
    foreach (nopayneProjectScopeRequestProjects() as $projectId) {
        if (!nopayneProjectScopeCanAccess($projectId)) nopayneProjectScopeDeny();
    }
}
