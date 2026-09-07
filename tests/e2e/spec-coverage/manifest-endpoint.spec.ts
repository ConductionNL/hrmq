/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `GET /apps/humaniq/api/manifest` serves the EFFECTIVE manifest, cheaply.
 *
 * The endpoint used to return `src/manifest.json`: the BASE manifest, 11
 * pages, before the 33 `src/manifest.d/` fragments, the pageTemplate
 * expansion and the `menu-layout.json` relocations are merged in. The
 * running app has 113. Nothing in the SPA noticed, because it imports the
 * base at build time and does the merge itself at boot, so the only reader
 * this endpoint ever had was a warm-up curl in `ci-seed.sh` checking a
 * status code. Any other consumer got a manifest describing an app that
 * does not exist.
 *
 * These tests run against a REAL build over HTTP, which is the only place
 * the second half can be observed at all: the 304 is produced by
 * Nextcloud's `NotModifiedMiddleware`, not by the controller, so a unit
 * test can assert the ETag is set but never that a repeat call is answered
 * with it.
 *
 * Read-only: no data is created or mutated.
 */

import { expect, test } from "@playwright/test";

const MANIFEST_URL = "/index.php/apps/humaniq/api/manifest";
const HEADERS = { "OCS-APIRequest": "true" };

test.describe("manifest endpoint", () => {
	/**
	 * The endpoint answers with the app's real page set, not the base one.
	 *
	 * @spec openspec/specs/humaniq-manifest-pipeline/spec.md
	 */
	test("serves the effective manifest, not the base manifest", async ({
		page,
	}) => {
		const res = await page.request.get(MANIFEST_URL, { headers: HEADERS });

		expect(res.status(), "the manifest endpoint must answer 200").toBe(200);

		const manifest = await res.json();
		const ids = (manifest.pages ?? []).map(
			(p: { id: string }) => p.id,
		) as string[];

		// 11 is the base manifest's page count. Seeing it here means the
		// fragments were not merged and the endpoint has regressed.
		expect(
			ids.length,
			`the endpoint returned ${ids.length} pages; 11 means the BASE manifest is being served again`,
		).toBeGreaterThan(11);

		// Both of these exist ONLY in a manifest.d fragment, so their absence
		// is proof the merge did not happen, independently of the count.
		expect(ids).toContain("TimeEntries");
		expect(ids).toContain("AssetDetail");
	});

	/**
	 * A repeat call costs a 304, not ~290KB of JSON.
	 *
	 * @spec openspec/specs/humaniq-manifest-pipeline/spec.md
	 */
	test("answers a conditional repeat call with 304 Not Modified", async ({
		page,
	}) => {
		const first = await page.request.get(MANIFEST_URL, {
			headers: HEADERS,
		});
		expect(first.status()).toBe(200);

		const etag = first.headers().etag;
		expect(
			etag,
			"no ETag means no repeat call can ever be answered 304",
		).toBeTruthy();

		const cacheControl = first.headers()["cache-control"];
		expect(
			cacheControl,
			`Cache-Control was "${cacheControl}"; a session-gated response must not be marked public`,
		).toContain("private");

		const second = await page.request.get(MANIFEST_URL, {
			headers: { ...HEADERS, "If-None-Match": etag },
		});

		expect(
			second.status(),
			"the framework must answer a matching If-None-Match with 304",
		).toBe(304);
	});
});
