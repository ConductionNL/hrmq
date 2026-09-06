/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * leave-approval-posts-to-the-balance, tasks.md 4.3.
 *
 * WHAT THIS CLOSES
 * ----------------
 * `LeaveBalance.usedHours` had no writer at all until #320: LeaveAccrualJob
 * seeded it to 0.0 and nothing ever moved it, so `remainingHours` reported the
 * full entitlement forever and three labour rule checks could never fire. The
 * projection is covered by PHPUnit at the arithmetic level, but nothing in CI
 * watched it work through the object store, which is where it actually runs
 * (an OpenRegister post-save listener, not a service call the app makes).
 *
 * This spec drives the real edge and reads the answer back from the
 * LeaveBalances page, so a regression shows up as a number a person can see
 * rather than only as a failing unit test.
 *
 * WHY THE FIXTURE CARRIES NO EXPLICIT `hours`
 * -------------------------------------------
 * `LeaveRequest.hours` is optional and a multi-day request usually omits it,
 * so the derivation is the common path, not the edge: working days in the
 * range x `contractHoursPerWeek / 5`. 2026-03-02 to 2026-03-06 is Monday to
 * Friday, five working days, which at a 40-hour week is 40 hours. Asserting a
 * derived number rather than an echoed one is the point: an assertion over an
 * explicit `hours` would still pass if the derivation broke entirely.
 *
 * PRECONDITIONS
 * -------------
 * The humaniq register and its schemas are imported (tests/e2e/ci-seed.sh).
 * The fixtures below are created and removed by this file, so it needs no
 * seeded object of its own.
 */

import type { APIRequestContext } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { randomUUID } from "node:crypto";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "../base-url.ts";

const NC_URL = resolveBaseURL();
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`;
const REGISTER = "humaniq";
const AUTH = ADMIN_CREDENTIALS;
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
};

/* Namespaces every fixture this run creates in a SHARED register, so a
   concurrent run cannot cross-contaminate it. */
const RUN_ID = randomUUID().slice(0, 8);

/** Read an object's id out of either payload shape OpenRegister returns. */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.["@self"] || {}) as Record<string, unknown>;
	return String(self.id || row?.id || "");
}

/* SERIAL on purpose: the tests share `requestId` and each builds on the last.
   Without it a retry re-runs only the failed test in a fresh worker, where the
   test that set the id never ran, and the PUT 404s on an empty id. */
test.describe.serial("leave approval posts to the balance", () => {
	let api: APIRequestContext;
	let employeeId = "";
	let balanceId = "";
	let requestId = "";

	const cleanup: Array<{ schema: string; id: string }> = [];

	/** The balance as the object store currently holds it. */
	async function readBalance(): Promise<Record<string, unknown>> {
		const res = await api.get(`${OR_BASE}/${REGISTER}/LeaveBalance/${balanceId}`, {
			headers: HEADERS,
		});
		expect(res.ok(), `balance must be readable (${res.status()})`).toBeTruthy();
		return (await res.json()) as Record<string, unknown>;
	}

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: AUTH });

		const emp = await api.post(`${OR_BASE}/${REGISTER}/Employee`, {
			headers: HEADERS,
			data: {
				employeeNumber: `${RUN_ID}-lv`,
				firstName: "Lea",
				lastName: `Verlof-${RUN_ID}`,
				startDate: "2026-01-01",
			},
		});
		expect(emp.ok(), `employee fixture (${emp.status()})`).toBeTruthy();
		employeeId = idOf(await emp.json());
		cleanup.push({ schema: "Employee", id: employeeId });

		// contractHoursPerWeek is what the derivation divides by, so it is the
		// load-bearing half of this fixture, not decoration.
		const bal = await api.post(`${OR_BASE}/${REGISTER}/LeaveBalance`, {
			headers: HEADERS,
			data: {
				employeeId,
				year: 2026,
				leaveType: "holiday",
				entitledHours: 160,
				bovenwettelijkHours: 0,
				usedHours: 0,
				contractHoursPerWeek: 40,
				// The LeaveBalances page filters on the caller's ACTIVE
				// administration, which ci-seed.sh points at ADM-001. Without
				// this the row is correct in the store and absent from the page.
				administrationId: "ADM-001",
			},
		});
		expect(bal.ok(), `balance fixture (${bal.status()})`).toBeTruthy();
		balanceId = idOf(await bal.json());
		cleanup.push({ schema: "LeaveBalance", id: balanceId });
	});

	test.afterAll(async () => {
		for (const { schema, id } of cleanup.reverse()) {
			if (!id) continue;
			await api
				.delete(`${OR_BASE}/${REGISTER}/${schema}/${id}`, { headers: HEADERS })
				.catch(() => {});
		}
		await api?.dispose();
	});

	test("a submitted request leaves the balance alone", async () => {
		const res = await api.post(`${OR_BASE}/${REGISTER}/LeaveRequest`, {
			headers: HEADERS,
			data: {
				employeeId,
				leaveType: "holiday",
				startDate: "2026-03-02",
				endDate: "2026-03-06",
				status: "submitted",
			},
		});
		expect(res.ok(), `request fixture (${res.status()})`).toBeTruthy();
		requestId = idOf(await res.json());
		cleanup.push({ schema: "LeaveRequest", id: requestId });

		const balance = await readBalance();
		expect(
			Number(balance.usedHours ?? 0),
			"only an APPROVED request counts against the balance",
		).toBe(0);
	});

	test("approving it posts the derived hours onto the balance", async () => {
		const res = await api.put(`${OR_BASE}/${REGISTER}/LeaveRequest/${requestId}`, {
			headers: HEADERS,
			data: {
				employeeId,
				leaveType: "holiday",
				startDate: "2026-03-02",
				endDate: "2026-03-06",
				status: "approved",
				approvedBy: "admin",
			},
		});
		expect(res.ok(), `approval must succeed (${res.status()})`).toBeTruthy();

		// Monday to Friday at a 40-hour week. A wrong derivation lands on 48
		// (calendar days) or 0 (no writer at all), so the number discriminates.
		await expect
			.poll(async () => Number((await readBalance()).usedHours ?? 0), {
				message: "approving leave must move usedHours",
				timeout: 20_000,
			})
			.toBe(40);
	});

	test("the balance the page shows is the balance the projection wrote", async ({
		page,
	}) => {
		await page.goto("/index.php/apps/humaniq/leave-balances", {
			waitUntil: "domcontentloaded",
		});
		await expect(page.locator("#app-content, .app-content").first()).toBeVisible({
			timeout: 30_000,
		});

		// The employeeId column renders the uuid TRUNCATED ("d466c269…"), not a
		// resolved name and not the full id, so match the visible prefix. It
		// still pins the row to the employee this run created, which is the
		// point: a seeded balance must not be able to satisfy this.
		//
		// getByRole, not a `tr` text match: the row's TEXT CONTENT runs the
		// cells together ("160040"), where its ACCESSIBLE NAME separates them
		// ("160 0 40"). Asserting on the former makes `40` unfindable next to a
		// 160 entitlement, and worse, findable by accident inside it.
		const row = page
			.getByRole("row", { name: new RegExp(employeeId.slice(0, 8)) })
			.first();
		await expect(
			row,
			"the balance this run created must be listed",
		).toBeVisible({ timeout: 30_000 });
		// Entitled 160, bovenwettelijk 0, used 40, in column order. Pinning all
		// three keeps the assertion from passing on a stray 40 elsewhere.
		await expect(
			row,
			"the page must show the 40 hours the projection posted",
		).toHaveAccessibleName(/160\s+0\s+40/);
	});

	test("a second identical projection writes nothing new", async () => {
		const before = await readBalance();
		const updatedBefore = String(
			((before["@self"] || {}) as Record<string, unknown>).updated || "",
		);

		// Re-saving the request unchanged re-runs the listener. A recompute is
		// idempotent, so the balance must not be written a second time — an
		// increment would land on 80 here.
		const res = await api.put(`${OR_BASE}/${REGISTER}/LeaveRequest/${requestId}`, {
			headers: HEADERS,
			data: {
				employeeId,
				leaveType: "holiday",
				startDate: "2026-03-02",
				endDate: "2026-03-06",
				status: "approved",
				approvedBy: "admin",
			},
		});
		expect(res.ok(), `re-save must succeed (${res.status()})`).toBeTruthy();

		const after = await readBalance();
		expect(
			Number(after.usedHours ?? 0),
			"recompute, not increment: the total must not double",
		).toBe(40);
		const updatedAfter = String(
			((after["@self"] || {}) as Record<string, unknown>).updated || "",
		);
		if (updatedBefore !== "") {
			expect(
				updatedAfter,
				"an unchanged projection must not touch the balance at all",
			).toBe(updatedBefore);
		}
	});
});
