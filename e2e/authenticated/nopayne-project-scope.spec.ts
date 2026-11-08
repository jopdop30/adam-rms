import { execFileSync } from "child_process";
import path from "path";
import { APP_ENV, BASE_URL, PHP_BINARY } from "../env";
import { bookings } from "../projects";
import {
  test as base, expect, formData, mentions, newSession, seedTenants, snapshot, succeeded,
  type Params, type Result, type Session, type Tenant, type Tenants,
} from "../tenants";

/**
 * No Payne fork (see NOPAYNE-FORK.md): PROJECTS:VIEW:ONLY_ASSIGNED limits a user to the projects they manage
 * or are crew on. setup/nopayneProjectScope.php adds to business A:
 *   - "restricted": every permission A has, plus the restriction, so a refusal can only be down to the restriction
 *   - "client": the role No Payne gives a client (view projects, add/remove assets, view crew and asset types)
 *   - an "own" project both are crew on, and a "managed" project "restricted" manages.
 * A's seeded project and sub-project (managed by A's full user, no crew from here) are the ones they must not reach.
 * Each refusal has a control: A's full-access user, who isn't restricted, can make the same request.
 */

type Scope = {
  users: { restricted: { id: number; email: string }; client: { id: number; email: string } };
  positions: { restricted: number; client: number };
  projects: { own: number; managed: number };
};

let lastScope: Scope | undefined;
/** Passes the IDs from the last call back in, so records are found even if a test changed them, and IDs don't change */
function seedScope(tenants: Tenants): Scope {
  const args = [path.join(__dirname, "..", "setup", "nopayneProjectScope.php"), JSON.stringify(tenants), ...(lastScope ? [JSON.stringify(lastScope)] : [])];
  const out = execFileSync(PHP_BINARY, args, {
    cwd: path.resolve(__dirname, "..", ".."),
    env: { ...process.env, ...APP_ENV },
    encoding: "utf8",
  });
  lastScope = JSON.parse(out);
  return lastScope!;
}

/** Puts A, B and this spec's records back how the setup scripts define them */
function reseed(): { tenants: Tenants; scope: Scope } {
  const tenants = seedTenants();
  return { tenants, scope: seedScope(tenants) };
}

const test = base.extend<{ scope: Scope }, { scopeSeeded: Scope; asRestricted: Session; asClient: Session }>({
  scopeSeeded: [async ({ seeded }, use) => use(seedScope(seeded)), { scope: "worker" }],
  scope: async ({ scopeSeeded }, use) => use(scopeSeeded),
  asRestricted: [
    async ({ playwright, scopeSeeded, seeded }, use) => {
      const request = await playwright.request.newContext({ baseURL: BASE_URL });
      await use(await newSession(request, scopeSeeded.users.restricted.email, seeded.password));
      await request.dispose();
    },
    { scope: "worker" },
  ],
  asClient: [
    async ({ playwright, scopeSeeded, seeded }, use) => {
      const request = await playwright.request.newContext({ baseURL: BASE_URL });
      await use(await newSession(request, scopeSeeded.users.client.email, seeded.password));
      await request.dispose();
    },
    { scope: "worker" },
  ],
});

/** A link to the project, as the sidebar, lists, calendars and search results print them */
const linksTo = (r: Result, id: number) => new RegExp(`/project/\\?id=${id}(?!\\d)`).test(r.body);
/** The project's ID as a JSON field */
const listsId = (r: Result, id: number) => new RegExp(`"projects_id":"?${id}(?!\\d)`).test(r.body);
/** How the scope check refuses an API call */
const refused = (r: Result) => r.json?.result === false && r.json?.error?.code === "AUTH-ERROR";

test.describe("listing projects", () => {
  test("api/projects/list.php lists only the user's projects", async ({ asRestricted, asA, tenants: { a }, scope }) => {
    for (const params of [{}, { subprojects: "true" }] as Params[]) {
      const response = await asRestricted.api("/api/projects/list.php", params);
      expect(response.json?.result, response.body.slice(0, 500)).toBe(true);
      const ids = response.json.response.flatMap((p: { projects_id: number; subprojects: { projects_id: number }[] }) => [p.projects_id, ...p.subprojects.map((s) => s.projects_id)]);
      expect(ids.sort()).toEqual([scope.projects.own, scope.projects.managed].sort());
    }
    // Control: unrestricted users still see every project
    const all = await asA.api("/api/projects/list.php", {});
    for (const id of [a.projectId, scope.projects.own, scope.projects.managed]) expect(listsId(all, id), `full user sees ${id}`).toBe(true);
  });

  test("a user with PROJECTS:VIEW and no restriction still sees every project", async ({ asLimitedA, tenants: { a }, scope }) => {
    const all = await asLimitedA.api("/api/projects/list.php", {});
    for (const id of [a.projectId, scope.projects.own, scope.projects.managed]) expect(listsId(all, id), `limited user sees ${id}`).toBe(true);
    expect(mentions(await asLimitedA.page(`/project/?id=${a.projectId}`), `${a.marker} project description`)).toBe(true);
  });

  const pages = [
    { name: "the sidebar and dashboard calendar", url: () => "/" },
    { name: "project/list.php", url: () => "/project/list.php" },
    { name: "the business calendar", url: () => "/instances/calendar.php" },
    { name: "search.php", url: (a: Tenant) => `/search.php?term=${a.marker}` },
    { name: "the user page of the other project's manager", url: (a: Tenant) => `/user.php?id=${a.users.full.id}` },
  ];
  for (const p of pages) {
    test(`${p.name} links only to the user's projects`, async ({ asRestricted, asA, tenants: { a }, scope }) => {
      const response = await asRestricted.page(p.url(a));
      expect(response.status).toBe(200);
      expect(linksTo(response, a.projectId), "links to A's seeded project").toBe(false);
      expect(linksTo(response, a.subProjectId), "links to A's seeded sub-project").toBe(false);
      expect(linksTo(await asA.page(p.url(a)), a.projectId), "control: the full user's page links to it").toBe(true);
    });
  }

  test("the sidebar lists the user's own projects", async ({ asRestricted, scope }) => {
    const response = await asRestricted.page("/");
    expect(linksTo(response, scope.projects.own)).toBe(true);
    expect(linksTo(response, scope.projects.managed)).toBe(true);
  });

  test("global search finds only the user's projects", async ({ asRestricted, asA, tenants: { a }, scope }) => {
    const search = (s: Session) => s.api("/api/search/search.php", { term: a.marker }, "GET");
    const response = await search(asRestricted);
    expect(response.json?.result, response.body.slice(0, 500)).toBe(true);
    expect(listsId(response, a.projectId)).toBe(false);
    expect(listsId(response, scope.projects.own), "finds its own project by client name").toBe(true);
    expect(listsId(await search(asA), a.projectId), "control").toBe(true);
  });

  test("the assets page offers only the user's projects to assign to", async ({ asRestricted, asClient, asA, tenants: { a }, scope }) => {
    const option = (id: number) => new RegExp(`value="${id}">[^<]*\\| ${a.marker} client`);
    for (const session of [asRestricted, asClient]) {
      const response = await session.page("/assets.php");
      expect(option(a.projectId).test(response.body)).toBe(false);
      expect(option(scope.projects.own).test(response.body)).toBe(true);
    }
    expect(option(a.projectId).test((await asA.page("/assets.php")).body), "control").toBe(true);
  });

  test("an asset's bookings on other projects don't give their names", async ({ asRestricted, asA, tenants: { a } }) => {
    const name = `${a.marker} project',`; // the end of the calendar entry's title
    expect(mentions(await asRestricted.page(`/asset.php?id=${a.assetTypeId}`), name)).toBe(false);
    expect(mentions(await asA.page(`/asset.php?id=${a.assetTypeId}`), name), "control").toBe(true);
  });

  test("the payments ledger and crew vacancies leave out other projects", async ({ asRestricted, asA, tenants: { a } }) => {
    for (const [url, text] of [["/ledger.php", `${a.marker} payment`], ["/project/crew/vacancies.php", `${a.marker} vacancy`]]) {
      expect(mentions(await asRestricted.page(url), text), url).toBe(false);
      expect(mentions(await asA.page(url), text), `control: ${url}`).toBe(true);
    }
  });

  test("the business calendar doesn't hand out the business-wide iCal feed", async ({ asRestricted, asA, tenants: { a } }) => {
    const feed = `key=e2e-calendar-hash-a`;
    expect(mentions(await asRestricted.page("/instances/calendar.php"), feed)).toBe(false);
    expect(mentions(await asA.page("/instances/calendar.php"), feed), "control").toBe(true);
  });
});

test.describe("opening a project", () => {
  test("the user's own projects open, as crew or as manager", async ({ asRestricted, asClient, scope }) => {
    for (const [session, id] of [[asRestricted, scope.projects.own], [asRestricted, scope.projects.managed], [asClient, scope.projects.own]] as const) {
      const page = await session.page(`/project/?id=${id}`);
      expect(page.status).toBe(200);
      expect(mentions(page, "nopayne"), `project ${id}`).toBe(true);
      const data = await session.api("/api/projects/data.php", { id });
      expect(data.json?.result, data.body.slice(0, 500)).not.toBe(false);
    }
  });

  const pages: { name: string; url: (a: Tenant) => string; shows: (a: Tenant) => string }[] = [
    { name: "project/index.php", url: (a: Tenant) => `/project/?id=${a.projectId}`, shows: (a: Tenant) => `${a.marker} project description` },
    { name: "project/index.php (sub-project)", url: (a: Tenant) => `/project/?id=${a.subProjectId}`, shows: (a: Tenant) => `${a.marker} sub-project` },
    { name: "project/projectInvoice.php", url: (a: Tenant) => `/project/projectInvoice.php?id=${a.projectId}`, shows: (a: Tenant) => `${a.marker}\\u0020project` },
    { name: "project/noteExport.php", url: (a: Tenant) => `/project/noteExport.php?id=${a.noteId}`, shows: (a: Tenant) => `${a.marker} note` },
    { name: "project/crew/vacantCrew.php", url: (a: Tenant) => `/project/crew/vacantCrew.php?id=${a.projectId}`, shows: (a: Tenant) => `${a.marker} vacancy` },
    { name: "project/crew/vacancy.php", url: (a: Tenant) => `/project/crew/vacancy.php?id=${a.vacantRoleId}`, shows: (a: Tenant) => `${a.marker} vacancy` },
    { name: "project/crew/applications.php", url: (a: Tenant) => `/project/crew/applications.php?id=${a.vacantRoleId}`, shows: (a: Tenant) => `${a.marker} application` },
    { name: "assets.php?project=", url: (a: Tenant) => `/assets.php?project=${a.projectId}`, shows: (a: Tenant) => `${a.marker} project | ${a.marker} client` },
  ];
  for (const p of pages) {
    test(`page ${p.name} is a 404 for another project's record`, async ({ asRestricted, asA, tenants: { a } }) => {
      const response = await asRestricted.page(p.url(a));
      expect(response.status).toBe(404);
      expect(mentions(response, p.shows(a))).toBe(false);
      expect(mentions(await asA.page(p.url(a)), p.shows(a)), "control: the full user sees it").toBe(true);
    });
  }

  const reads: { label: string; endpoint: string; params: (a: Tenant) => Params; shows: (a: Tenant) => string; method?: "GET" }[] = [
    { label: "id", endpoint: "/api/projects/data.php", params: (a) => ({ id: a.projectId }), shows: (a) => `${a.marker} project description` },
    { label: "formData id", endpoint: "/api/projects/data.php", params: (a) => ({ formData: formData({ id: a.projectId }) }), shows: (a) => `${a.marker} project description` },
    { label: "projects_id", endpoint: "/api/projects/assets/statusList.php", params: (a) => ({ projects_id: a.projectId }), shows: (a) => `${a.marker} assignment comment` },
    { label: "projects_id", endpoint: "/api/projects/getComments.php", params: (a) => ({ projects_id: a.projectId }), shows: (a) => `${a.marker} quick comment` },
    { label: "projects_id", endpoint: "/api/projects/crew/crewRoles/list.php", params: (a) => ({ projects_id: a.projectId }), shows: (a) => `${a.marker} vacancy` },
    { label: "projectsVacantRoles_id", endpoint: "/api/projects/crew/crewRoles/applicationList.php", params: (a) => ({ projectsVacantRoles_id: a.vacantRoleId }), shows: (a) => `${a.marker} application` },
    { label: "assetsAssignments_id", endpoint: "/api/assets/substitutions.php", params: (a) => ({ assetsAssignments_id: a.assignmentId }), shows: (a) => a.spareAssetTag },
    { label: "GET with instances_id, as the app sends it", endpoint: "/api/projects/data.php", params: (a) => ({ id: a.projectId, instances_id: a.instanceId }), shows: (a) => `${a.marker} project description`, method: "GET" },
  ];
  for (const r of reads) {
    test(`${r.endpoint} (${r.label}) refuses another project's record`, async ({ asRestricted, asA, tenants: { a } }) => {
      const response = await asRestricted.api(r.endpoint, r.params(a), r.method);
      expect(refused(response), response.body.slice(0, 500)).toBe(true);
      expect(mentions(response, r.shows(a))).toBe(false);
      expect(mentions(await asA.api(r.endpoint, r.params(a), r.method), r.shows(a)), "control: the full user gets it").toBe(true);
    });
  }
});

type WriteCase = { endpoint: string; params: (a: Tenant, scope: Scope) => Params };
// The same requests tenant-isolation.spec.ts makes, aimed at A's seeded project and its records
const writes: WriteCase[] = [
  { endpoint: "/api/projects/changeName.php", params: (a) => ({ projects_id: a.projectId, projects_name: "Renamed by e2e" }) },
  { endpoint: "/api/projects/changeDescription.php", params: (a) => ({ projects_id: a.projectId, projects_description: "Changed by e2e" }) },
  { endpoint: "/api/projects/changeDeliveryNotes.php", params: (a) => ({ projects_id: a.projectId, projects_deliveryNotes: "Changed by e2e" }) },
  { endpoint: "/api/projects/changeInvoiceNotes.php", params: (a) => ({ projects_id: a.projectId, projects_invoiceNotes: "Changed by e2e" }) },
  { endpoint: "/api/projects/changeProjectDates.php", params: (a) => ({ projects_id: a.projectId, projects_dates_use_start: "2031-01-01 09:00", projects_dates_use_end: "2031-01-02 09:00" }) },
  { endpoint: "/api/projects/changeProjectDeliverDates.php", params: (a) => ({ projects_id: a.projectId, projects_dates_deliver_start: "2031-01-01 09:00", projects_dates_deliver_end: "2031-01-05 09:00" }) },
  { endpoint: "/api/projects/changeProjectFinanceDurationMaths.php", params: (a) => ({ projects_id: a.projectId, projects_dates_finances_days: 3, projects_dates_finances_weeks: 1 }) },
  { endpoint: "/api/projects/archive.php", params: (a) => ({ projects_id: a.projectId }) },
  { endpoint: "/api/projects/delete.php", params: (a) => ({ projects_id: a.projectId }) },
  { endpoint: "/api/projects/changeStatus.php", params: (a) => ({ projects_id: a.projectId, projectsStatuses_id: a.projectStatusIds.second }) },
  { endpoint: "/api/projects/changeProjectManager.php", params: (a, s) => ({ projects_id: a.projectId, users_userid: s.users.restricted.id }) },
  { endpoint: "/api/projects/changeSubProject.php", params: (a) => ({ projects_id: a.subProjectId, projects_parent_project_id: -1 }) },
  // Moving the user's own project under someone else's
  { endpoint: "/api/projects/changeSubProject.php", params: (a, s) => ({ projects_id: s.projects.managed, projects_parent_project_id: a.projectId }) },
  { endpoint: "/api/projects/followParentStatus.php", params: (a) => ({ projects_id: a.subProjectId, follow: "true" }) },
  { endpoint: "/api/projects/new.php", params: (a, s) => ({ projects_name: "Sub-project by e2e", projectsType_id: a.projectTypeId, projects_manager: s.users.restricted.id, projects_parent_project_id: a.projectId }) },
  { endpoint: "/api/projects/newNote.php", params: (a) => ({ projects_id: a.projectId, projectsNotes_title: "Note by e2e" }) },
  { endpoint: "/api/projects/editNote.php", params: (a) => ({ projects_id: a.projectId, projectsNotes_id: a.noteId, projectsNotes_text: "Changed by e2e" }) },
  { endpoint: "/api/projects/newPayment.php", params: (a) => ({ formData: formData({ projects_id: a.projectId, payments_amount: "1.00", payments_type: 1, payments_date: "2024-02-01" }) }) },
  { endpoint: "/api/projects/deletePayment.php", params: (a) => ({ payments_id: a.paymentId }) },
  { endpoint: "/api/projects/newQuickComment.php", params: (a) => ({ projects_id: a.projectId, text: "Comment by e2e" }) },
  { endpoint: "/api/projects/assets/assign.php", params: (a) => ({ projects_id: a.projectId, assets_id: a.spareAssetId }) },
  { endpoint: "/api/projects/assets/unassign.php", params: (a) => ({ assetsAssignments: [a.assignmentId] }) },
  { endpoint: "/api/projects/assets/swap.php", params: (a) => ({ assetsAssignments_id: a.assignmentId, assets_id: a.spareAssetId }) },
  { endpoint: "/api/projects/assets/setComment.php", params: (a) => ({ assetsAssignments: [a.assignmentId], assetsAssignments_comment: "Changed by e2e" }) },
  { endpoint: "/api/projects/assets/setDiscount.php", params: (a) => ({ assetsAssignments: [a.assignmentId], assetsAssignments_discount: 10 }) },
  { endpoint: "/api/projects/assets/setPrice.php", params: (a) => ({ assetsAssignments: [a.assignmentId], assetsAssignments_customPrice: "5.00" }) },
  { endpoint: "/api/projects/assets/setStatus.php", params: (a) => ({ assetsAssignments_id: a.assignmentId, assetsAssignments_status: a.assignmentStatusId }) },
  { endpoint: "/api/projects/assets/setStatusByTag.php", params: (a) => ({ projects_id: a.projectId, text: a.assetTag, assetsAssignments_status: a.assignmentStatusId }) },
  { endpoint: "/api/projects/assets/setStatusBarcode.php", params: (a) => ({ projects_id: a.projectId, text: a.barcodeValue, type: "CODE_128", assetsAssignments_status: a.assignmentStatusId }) },
  { endpoint: "/api/projects/crew/edit.php", params: (a) => ({ crewAssignments_id: a.crewAssignmentId, crewAssignments_comment: "Changed by e2e" }) },
  { endpoint: "/api/projects/crew/unassign.php", params: (a) => ({ crewAssignments_id: a.crewAssignmentId }) },
  { endpoint: "/api/projects/crew/sortRank.php", params: (a) => ({ projects_id: a.projectId, order: [a.crewAssignmentId] }) },
  { endpoint: "/api/projects/crew/crewRoles/edit.php", params: (a) => ({ formData: formData({ projects_id: a.projectId, projectsVacantRoles_id: a.vacantRoleId, projectsVacantRoles_name: "Renamed by e2e" }) }) },
  // Applying for a role would make the user crew, and so let them in
  { endpoint: "/api/projects/crew/crewRoles/apply.php", params: (a) => ({ formData: formData({ projectsVacantRoles_id: a.vacantRoleId, projectsVacantRolesApplications_applicantComment: "Applied by e2e" }) }) },
  { endpoint: "/api/projects/crew/crewRoles/accept.php", params: (a) => ({ projectsVacantRolesApplications_id: a.vacancyApplicationId }) },
  { endpoint: "/api/projects/crew/crewRoles/reject.php", params: (a) => ({ projectsVacantRolesApplications_id: a.vacancyApplicationId }) },
];

test.describe("changing another project", () => {
  for (const [i, c] of writes.entries()) {
    test(`${c.endpoint} (${i}) leaves another project alone`, async ({ asRestricted, asA, tenants: { a }, scope }) => {
      const before = snapshot(a.instanceId);
      const response = await asRestricted.api(c.endpoint, c.params(a, scope));
      const after = snapshot(a.instanceId);
      expect(refused(response), response.body.slice(0, 500)).toBe(true);
      expect(after).toEqual(before);

      // Control: the same request from an unrestricted user does change something
      await asA.api(c.endpoint, c.params(a, scope));
      expect(snapshot(a.instanceId), "control: the full user's request changes A's records").not.toEqual(before);
      reseed();
    });
  }

  test("the user can still change their own projects", async ({ asRestricted, tenants: { a }, scope }) => {
    for (const id of [scope.projects.own, scope.projects.managed]) {
      const renamed = await asRestricted.api("/api/projects/changeDescription.php", { projects_id: id, projects_description: `nopayne changed ${id}` });
      expect(succeeded(renamed), renamed.body.slice(0, 500)).toBe(true);
      const note = await asRestricted.api("/api/projects/newNote.php", { projects_id: id, projectsNotes_title: "nopayne note" });
      expect(succeeded(note), note.body.slice(0, 500)).toBe(true);
    }
    reseed();
  });
});

test.describe("adding and removing assets", () => {
  for (const who of ["asClient", "asRestricted"] as const) {
    test(`${who === "asClient" ? "the client role" : "the restricted user"} can add an asset to their project and take it off again`, async ({ asClient, asRestricted, tenants: { a }, scope }) => {
      const session = who === "asClient" ? asClient : asRestricted;
      const added = await session.api("/api/projects/assets/assign.php", { projects_id: scope.projects.own, assets_id: a.spareAssetId });
      expect(succeeded(added), added.body.slice(0, 500)).toBe(true);
      expect(bookings(a.spareAssetId)).toEqual([scope.projects.own]);

      // The project page lists it
      expect(mentions(await session.page(`/project/?id=${scope.projects.own}`), a.spareAssetTag)).toBe(true);

      // As the assets page's basket button removes it
      const removed = await session.api("/api/projects/assets/unassign.php", { assets_id: a.spareAssetId, projects_id: scope.projects.own });
      expect(succeeded(removed), removed.body.slice(0, 500)).toBe(true);
      expect(bookings(a.spareAssetId)).toEqual([]);
      reseed();
    });
  }

  test("the client role can't add an asset to, or take one off, another project", async ({ asClient, tenants: { a } }) => {
    const before = snapshot(a.instanceId);
    for (const [endpoint, params] of [
      ["/api/projects/assets/assign.php", { projects_id: a.projectId, assets_id: a.spareAssetId }],
      ["/api/projects/assets/assign.php", { projects_id: a.projectId, assetTypes_id: a.assetTypeId }],
      ["/api/projects/assets/unassign.php", { assetsAssignments: [a.assignmentId] }],
      ["/api/projects/assets/unassign.php", { assets_id: a.assetId, projects_id: a.projectId }],
    ] as [string, Params][]) {
      const response = await asClient.api(endpoint, params);
      expect(refused(response), `${endpoint}: ${response.body.slice(0, 500)}`).toBe(true);
    }
    expect(snapshot(a.instanceId)).toEqual(before);
    expect(bookings(a.spareAssetId)).toEqual([]);
    expect(bookings(a.assetId)).toEqual([a.projectId]);
  });
});
