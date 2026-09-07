/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * source-hash.js — one definition of "what src/ currently is".
 *
 * The mtime check next door answers "was the bundle built after the source
 * changed", and it needs git to do it: `src/`'s working-tree mtime is reset by
 * any clone, so the source side reads git's last-commit time instead. That
 * makes the whole check UNAVAILABLE in a git-less deploy, which is exactly
 * where a stale bundle does its damage, because an unpacked release tarball is
 * what an operator actually runs.
 *
 * A content hash needs no git and no timestamps. The build records one; the
 * check recomputes it. If they differ, the bundle was not built from this
 * source, whatever the dates say.
 *
 * BOTH SIDES MUST HASH THE SAME THING, so both call this. A build-side and a
 * check-side implementation that merely look equivalent is the defect this
 * file exists to prevent: they drift, and the resulting mismatch reports a
 * stale bundle that is perfectly current.
 */

const { execFileSync } = require("child_process");
const crypto = require("crypto");
const fs = require("fs");
const path = require("path");

/**
 * Every file under src/, as repo-relative POSIX paths, sorted.
 *
 * Prefers git's index, which is authoritative about what ships and silently
 * ignores build droppings. Falls back to walking the directory when git is
 * absent (the deploy case this whole file is for). The chosen source is
 * returned alongside, because a hash computed over a different file set is a
 * different hash, and a caller comparing two of them must be able to see that
 * rather than read it as a stale bundle.
 *
 * @param {string} repoRoot Absolute path to the repository root.
 * @return {{files: string[], listedBy: "git" | "walk"}}
 */
function listSourceFiles(repoRoot) {
	try {
		const out = execFileSync("git", ["ls-files", "src"], {
			cwd: repoRoot,
			encoding: "utf8",
			stdio: ["ignore", "pipe", "ignore"],
		});
		const files = out.split("\n").filter(Boolean).sort();
		if (files.length > 0) {
			return { files, listedBy: "git" };
		}
	} catch {
		// No git, no index, or not a repository: fall through to the walk.
	}

	const files = [];
	const walk = (dir) => {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
			const abs = path.join(dir, entry.name);
			if (entry.isDirectory()) {
				walk(abs);
			} else if (entry.isFile()) {
				files.push(path.relative(repoRoot, abs).split(path.sep).join("/"));
			}
		}
	};
	walk(path.join(repoRoot, "src"));
	return { files: files.sort(), listedBy: "walk" };
}

/**
 * A sha256 over src/'s paths AND contents.
 *
 * The path goes into the digest as well as the bytes, so a rename with no
 * content change still moves the hash: a moved page is a different app even
 * when every byte is accounted for.
 *
 * @param {string} repoRoot Absolute path to the repository root.
 * @return {{sourceHash: string, fileCount: number, listedBy: "git" | "walk"}}
 */
function computeSourceHash(repoRoot) {
	const { files, listedBy } = listSourceFiles(repoRoot);
	const digest = crypto.createHash("sha256");
	for (const rel of files) {
		digest.update(rel, "utf8");
		digest.update("\0");
		digest.update(fs.readFileSync(path.join(repoRoot, rel)));
		digest.update("\0");
	}

	return { sourceHash: digest.digest("hex"), fileCount: files.length, listedBy };
}

module.exports = { computeSourceHash, listSourceFiles };
