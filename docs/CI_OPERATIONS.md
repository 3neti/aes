# CI Operations

## Controlled Browser Artifact Check

Use this procedure after changing browser-test artifact wiring in `.github/workflows/tests.yml`.

The `tests` workflow has a manual `workflow_dispatch` input named `force_browser_artifact_failure`. When enabled, the `browser` job runs its normal setup and browser tests, writes marker files to the screenshot and backend-log artifact paths, then fails intentionally so the failure-only upload steps run.

### Procedure

1. Open GitHub Actions for the repository.
2. Select the `tests` workflow.
3. Choose `Run workflow`.
4. Enable `force_browser_artifact_failure`.
5. Start the workflow.
6. Wait for the `browser` job to fail after the `Browser Tests` step.
7. Confirm both artifacts are present on the workflow run:
   - `browser-screenshots`
   - `browser-backend-logs`
8. Download `browser-screenshots` and confirm it includes `controlled-browser-artifact-failure.txt`.
9. Download `browser-backend-logs` and confirm it includes `controlled-browser-artifact-failure.log`.
10. Record the workflow URL, run number, date, and result in the project notes or release checklist.

### Expected Result

The workflow run should fail intentionally. The check passes when both artifacts are attached and both marker files are present.

### When Not To Use It

Do not enable `force_browser_artifact_failure` on routine CI verification. It is only for validating the artifact upload path after workflow changes.

### Follow-Up If Artifacts Are Missing

If the workflow fails before marker files are written, inspect setup logs first: checkout, PHP setup, Node setup, Composer install, `npm ci`, Playwright install, environment setup, key generation, and asset build.

If marker files are written but artifacts are missing, inspect the `Upload Browser Screenshots` and `Upload Browser Backend Logs` steps and verify the upload paths still match:

```text
tests/Browser/Screenshots
storage/logs
```
