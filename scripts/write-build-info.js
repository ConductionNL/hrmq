#!/usr/bin/env node
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * write-build-info.js — the sidecar the build leaves behind, so a deploy can
 * still answer "was this bundle built from this source".
 *
 * Run from `postbuild`, unconditionally, on every successful build. It records
 * a content hash of src/ rather than a timestamp, because the timestamp-based
 * check next door needs git and a git-less deploy has none. A hash needs
 * neither.
 *
 * `postbuild` runs only when `build` exits 0, so the sidecar can never claim a
 * failed build produced this bundle.
 */

const fs = require("fs");
const path = require("path");
const { computeSourceHash } = require("./lib/source-hash.js");

const REPO_ROOT = path.resolve(__dirname, "..");
const OUT = path.join(REPO_ROOT, "js", "build-info.json");

/**
 * The app version, read from appinfo/info.xml.
 *
 * NOT from package.json: that says 0.1.0 and has since the app was scaffolded,
 * while info.xml carries the version the release machinery actually bumps and
 * the App Store publishes. Recording the wrong one would make the sidecar
 * disagree with every other version signal in the repo.
 *
 * @return {string}
 */
function appVersion() {
	try {
		const xml = fs.readFileSync(path.join(REPO_ROOT, "appinfo", "info.xml"), "utf8");
		const match = xml.match(/<version>([^<]+)<\/version>/);
		return match ? match[1].trim() : "unknown";
	} catch {
		return "unknown";
	}
}

const { sourceHash, fileCount, listedBy } = computeSourceHash(REPO_ROOT);
const info = {
	sourceHash,
	builtAt: new Date().toISOString(),
	appVersion: appVersion(),
	fileCount,
	listedBy,
};

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, JSON.stringify(info, null, "\t") + "\n");

console.log(
	`[build-info] js/build-info.json: ${fileCount} src file(s) via ${listedBy}, sourceHash ${sourceHash.slice(0, 12)}…, appVersion ${info.appVersion}`,
);
