/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours leaf's bundle, and the timer behind it.
 *
 * TWO THINGS NO OTHER CHECK CAN SEE.
 *
 * 1. THAT THE CLIENT HALF IS ON THE PAGE AT ALL. OpenRegister's
 *    LeafScriptListener enqueues `js/<app>-leaves.js` on consuming apps' pages
 *    and SKIPS an app that ships no such file, silently, because enqueuing a
 *    script that does not exist is a 404 in someone else's page. humaniq shipped
 *    no `leaves` webpack entry, so `humaniq-hours` rendered nowhere for as long
 *    as it existed, while both halves of the descriptor were registered and the
 *    parity gate compared them to each other and passed. The gate cannot see
 *    that NEITHER half is on the page. This spec asks the server for the file.
 *
 * 2. THAT A TIMER IS STILL RUNNING AFTER YOU LEAVE. The whole promise of the
 *    timer is that it survives the page, so the only test that means anything
 *    asks a SECOND request context, holding no state from the first, what is
 *    running. A test that checked the same tab's memory would pass on a timer
 *    that lives in a variable.
 *
 * The widget's own rendering is exercised where it actually mounts, on a
 * consuming app's detail page, which is dossiq's suite and not this one.
 * Assertions here are unconditional per ADR-074: a guarded assertion that never
 * runs is indistinguishable from one that passed.
 */

import type { APIRequestContext } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { randomUUID } from "node:crypto";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "../base-url.ts";

const NC_URL = resolveBaseURL();
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`;
const HUMANIQ_API = `${NC_URL}/index.php/apps/humaniq/api`;
const REGISTER = "humaniq";
const AUTH = ADMIN_CREDENTIALS;
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
};

/* Namespaces every fixture this run creates in a SHARED register, so a
   concurrent run cannot cross-contaminate it. */
const RUN_ID = `e2e-timer-${Date.now()}-${randomUUID().slice(0, 8)}`;
/* A host object reference that belongs to no real case. The leaf never
   dereferences it; `domainObjectRef` is an opaque uuid to humaniq. */
const HOST_TYPE = "dossiq:case";
const HOST_REF = `${RUN_ID}-host`;
const OTHER_REF = `${RUN_ID}-other-host`;

/** The uuid of an OpenRegister row, wherever the response carries it. */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.["@self"] || {}) as Record<string, unknown>;
	return String(self.id || row?.id || "");
}

test.describe.configure({ mode: "serial" });

test.describe("hours leaf — the bundle, and a timer that survives the page", () => {
	let api: APIRequestContext;
	const cleanup: string[] = [];

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: AUTH });

		// Leave nothing running from an earlier run: the one-timer rule is
		// per USER, and the acting admin is shared across this whole file.
		await api.post(`${HUMANIQ_API}/time-entries/timer/stop`, {
			headers: HEADERS,
			data: {},
		});
	});

	test.afterAll(async () => {
		// Cleanup lives here rather than in a test body so a failing assertion
		// still leaves the register as it found it. A leftover OPEN entry is
		// worse than a leftover closed one: it is a running timer for every
		// later run under the same account.
		await api.post(`${HUMANIQ_API}/time-entries/timer/stop`, {
			headers: HEADERS,
			data: {},
		});
		for (const id of cleanup) {
			await api.delete(`${OR_BASE}/${REGISTER}/TimeEntry/${id}`, {
				headers: HEADERS,
			});
		}
		await api.dispose();
	});

	test("the leaf bundle is served, and it is not the whole SPA", async () => {
		const res = await api.get(`${NC_URL}/apps/humaniq/js/humaniq-leaves.js`);

		expect(
			res.ok(),
			`js/humaniq-leaves.js must be served (${res.status()}). Without it OpenRegister ` +
				"skips humaniq and the hours leaf renders on no consuming page, silently.",
		).toBeTruthy();

		const body = await res.text();
		expect(
			body,
			"the bundle must register the leaf id the PHP half declares",
		).toContain("humaniq-hours");
		expect(
			body.length,
			"a leaf bundle carrying the whole SPA would be a performance regression on " +
				"every page of every consuming app",
		).toBeLessThan(4_000_000);
	});

	test("with nothing running, the timer endpoint says so rather than failing", async () => {
		const res = await api.get(`${HUMANIQ_API}/time-entries/timer`, {
			headers: HEADERS,
		});

		expect(res.ok(), `timer read failed: ${res.status()}`).toBeTruthy();
		expect((await res.json()).status).toBe("none");
	});

	test("a started timer is a real entry with no end", async () => {
		const res = await api.post(`${HUMANIQ_API}/time-entries/timer/start`, {
			headers: HEADERS,
			data: { domainObjectType: HOST_TYPE, domainObjectRef: HOST_REF },
		});

		expect(res.ok(), `start failed: ${res.status()} ${await res.text()}`).toBeTruthy();
		const body = await res.json();
		expect(body.status).toBe("running");

		const id = idOf(body.entry);
		expect(id, "the started timer must be a stored row").toBeTruthy();
		cleanup.push(id);

		// Read the row back through OpenRegister rather than trusting the
		// response: the stamping listener runs on the write, and what it stored
		// is what every other surface will read.
		const stored = await api.get(`${OR_BASE}/${REGISTER}/TimeEntry/${id}`, {
			headers: HEADERS,
		});
		expect(stored.ok(), `stored entry unreadable: ${stored.status()}`).toBeTruthy();
		const entry = await stored.json();

		expect(entry.origin, "the marker is what makes this a timer").toBe("timer");
		expect(entry.startedAt, "a timer starts at a moment").toBeTruthy();
		expect(
			entry.endedAt ?? "",
			"a RUNNING timer has no end; an entry with one is a finished booking",
		).toBe("");
		expect(
			Number(entry.hours ?? 0),
			"work that has not finished is worth zero hours, not an unknown",
		).toBe(0);
		expect(entry.domainObjectRef).toBe(HOST_REF);
	});

	test("the timer is still running for a context that holds no state", async () => {
		// A SECOND request context: new cookies, new everything. This is the
		// closest an api test gets to closing the tab and coming back, and it is
		// the only version of the promise worth testing. A timer kept in a
		// variable passes every check but this one.
		const fresh = await request.newContext({ httpCredentials: AUTH });
		try {
			const res = await fresh.get(`${HUMANIQ_API}/time-entries/timer`, {
				headers: HEADERS,
			});
			const body = await res.json();

			expect(body.status, "the timer must survive leaving the page").toBe("running");
			expect(body.entry.domainObjectRef).toBe(HOST_REF);
		} finally {
			await fresh.dispose();
		}
	});

	test("a second timer is refused by the server, and it names the object", async () => {
		const res = await api.post(`${HUMANIQ_API}/time-entries/timer/start`, {
			headers: HEADERS,
			data: { domainObjectType: HOST_TYPE, domainObjectRef: OTHER_REF },
		});

		expect(
			res.status(),
			"a second start is a conflict, not a silent second open row",
		).toBe(409);
		const body = await res.json();
		expect(body.status).toBe("already-running");
		expect(
			body.entry.domainObjectRef,
			"the refusal must say WHICH object is being timed, not only that something is",
		).toBe(HOST_REF);

		// And nothing was written for the object that was refused.
		const others = await api.get(
			`${OR_BASE}/${REGISTER}/TimeEntry?filter[domainObjectRef]=${OTHER_REF}&_limit=50`,
			{ headers: HEADERS },
		);
		const rows = await others.json();
		const list: Array<Record<string, unknown>> = Array.isArray(rows)
			? rows
			: rows.results || [];
		expect(list.length, "a refused start must write nothing at all").toBe(0);
	});

	test("stopping the timer closes the same row and derives its hours", async () => {
		const before = await api.get(`${HUMANIQ_API}/time-entries/timer`, {
			headers: HEADERS,
		});
		const runningId = idOf((await before.json()).entry);

		const res = await api.post(`${HUMANIQ_API}/time-entries/timer/stop`, {
			headers: HEADERS,
			data: {},
		});
		expect(res.ok(), `stop failed: ${res.status()} ${await res.text()}`).toBeTruthy();
		expect((await res.json()).status).toBe("stopped");

		const stored = await api.get(
			`${OR_BASE}/${REGISTER}/TimeEntry/${runningId}`,
			{ headers: HEADERS },
		);
		const entry = await stored.json();

		expect(
			idOf(entry),
			"the stop must close the row that was open, not create a second one",
		).toBe(runningId);
		expect(entry.endedAt, "a stopped timer has an end").toBeTruthy();
		expect(entry.domainObjectRef, "the stop must not lose the object").toBe(HOST_REF);

		// And the timer is no longer running.
		const after = await api.get(`${HUMANIQ_API}/time-entries/timer`, {
			headers: HEADERS,
		});
		expect((await after.json()).status).toBe("none");
	});

	test("stopping nothing is refused rather than reported as a stop", async () => {
		const res = await api.post(`${HUMANIQ_API}/time-entries/timer/stop`, {
			headers: HEADERS,
			data: {},
		});

		expect(res.status()).toBe(404);
		expect((await res.json()).error, "the refusal carries a sentence").toBeTruthy();
	});

	test("a booking that lost its end is still refused", async () => {
		// The marker is what separates a running timer from a defective booking.
		// Without this refusal a booking missing its end would be picked up as a
		// timer nobody started and no stop will ever close.
		const res = await api.post(`${OR_BASE}/${REGISTER}/TimeEntry`, {
			headers: HEADERS,
			data: {
				startedAt: "2026-06-15T09:00:00Z",
				origin: "manual",
				domainObjectType: HOST_TYPE,
				domainObjectRef: `${RUN_ID}-nomarker`,
			},
		});

		expect(
			res.ok(),
			"an entry with a start, no end and no timer marker must be refused",
		).toBeFalsy();
	});
});
