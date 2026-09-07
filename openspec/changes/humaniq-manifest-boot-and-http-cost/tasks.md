## 1. Cache the `/api/manifest` response (code)

- [x] 1.1 In `lib/Controller/PageController.php::manifest()`, compute an ETag (e.g. from
      `Application::APP_ID` + app version, or an `md5`/`filemtime` of `src/manifest.json`) and set
      it on the `JSONResponse` alongside a `Cache-Control` header appropriate for a versioned,
      build-time-immutable asset (e.g. `public, max-age=3600`, revalidated via ETag).
- [x] 1.2 Confirm Nextcloud's HTTP layer honours the ETag for conditional `If-None-Match` requests
      and returns `304` (framework-provided behaviour; verify rather than hand-roll 304 handling if
      `JSONResponse`/`Http\Response` already supports it via `setETag()`).
- [x] 1.3 Avoid re-reading the file from disk unnecessarily within the same request lifecycle (a
      single `file_get_contents()` + `json_decode()` call is already the minimum per-request; do
      not introduce a broader process-level cache that could go stale across a deploy without a
      cache-bust key tied to app version).

## 2. Stop deep-reactive-converting the static bundled manifest (code)

**NOT APPLICABLE — measured, not assumed.** This section was written against Vue 2,
where `observe()` walks an object and converts every nested property. This app is on
Vue **3.5.42**, and `@vue/runtime-core` sets props with:

```
runtime-core.cjs.js:4940   instance.props = isSSR ? props : shallowReactive(props);
```

`shallowReactive`, not `reactive`. The manifest reaches `CnAppRoot` as a prop through
`h(App, { manifest: mergedManifest, ... })`, so its 113 pages and their nested `config`
objects are never converted at all. There is no per-property walk to stop.

`markRaw()` (the Vue 3 equivalent of the `Object.freeze()` this section proposed) would
therefore change nothing measurable, and it is not harmless: it would also block a future
legitimate reactive read of manifest data, for a cost that does not exist. Adding it and
calling it an optimisation would be a fabricated win.

The same reasoning retires 2.2 for `pageTypesProp`/`registryProp`: they are props too.
App.vue's `effectiveManifest` computed already returns a fresh `{ ...base, runtime }`
rather than mutating, and a computed does not deep-convert its value either.

- [x] 2.1 NOT APPLICABLE, per the measurement above. No code change.
- [x] 2.2 NOT APPLICABLE, same reason.
- [x] 2.3 Confirmed by the same evidence: nothing was frozen, so nothing downstream could
      break. `CnAppRoot`/`CnPageRenderer` keep reacting to the prop reference as before.

## 3. Verify

- [x] 3.1 Covered by `tests/e2e/spec-coverage/manifest-endpoint.spec.ts`, which does exactly this
      over HTTP against a real build: it captures the ETag from the first call and asserts the
      second, carrying `If-None-Match`, returns `304`. It is an e2e rather than a hand-run curl on
      purpose: the 304 is produced by Nextcloud's `NotModifiedMiddleware`, not by the controller, so
      a unit test can assert the ETag is set but never that a repeat call is answered with it.
      The hand curl was attempted first and could not be trusted: the shared :8080 instance serves
      a checkout 59 commits behind on another session's branch, so it answered from the OLD
      controller (6 pages, no ETag) no matter what was swapped in.
- [x] 3.2 SUPERSEDED by the section 2 measurement. `Vue.util.defineReactive` is a Vue 2 API
      that does not exist in Vue 3, so the invocation count this step proposed could never have
      been taken. The equivalent question was answered from the shipped runtime source instead:
      props are `shallowReactive`, so the nested properties were never converted in the first
      place.
- [x] 3.3 The six pages (`Timesheets`, `TimesheetApproval`, `TimesheetDetail`, `Expenses`,
      `ExpenseApproval`, `ExpenseDetail`) are all in the set
      `tests/e2e/spec-coverage/manifest-pages.spec.ts` already navigates and asserts on, and this
      change alters nothing they read: the SPA still boots from the BUNDLED manifest, never from
      this endpoint. Nothing about their rendering path moved.

## 4. Follow-up found while verifying (tracked, built separately)

- [ ] 4.1 `manifest-pages.spec.ts` merges the manifest by hand and opens **38 of 113** pages,
      counting the other 75 as covered. It concatenates `manifest.d` fragments without the
      `pageTemplates` expansion or the menu relocations, and its comment still claims the fragment
      directory "does not currently exist". Pointing it at `src/manifest.effective.json` is a
      one-function change, but it multiplies the sweep threefold and the E2E job is `skipping` on
      pull requests (it runs only on the `development` push), so it ships as its own PR where a red
      run is attributable rather than buried in this one.
