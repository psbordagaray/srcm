# Straleon — Engineering Execution Contract V1

Status: **BINDING FOR DEVELOPMENT AUTOMATION AND AI-GENERATED RUNNERS**  
Product: **Straleon**  
Repository: `psbordagaray/srcm`  
Primary local environment: Windows + PowerShell + Laragon  
Effective date: **2026-09-07**

---

## 1. Purpose

This contract makes recurring execution details deterministic so engineering attention stays on Straleon domain logic instead of repeating avoidable tooling mistakes.

It governs:

- LF vs CRLF;
- UTF-8 / BOM;
- final newline;
- trailing whitespace;
- Git output parsing;
- exact scope comparison;
- source-write preflight;
- rollback;
- test reuse;
- checkpoint / push;
- remote / CI verification;
- formatting churn;
- development cadence.

---

## 2. Canonical text format

The repository already answers the EOL question.

`.gitattributes`:

```text
* text=auto eol=lf
```

`.editorconfig`:

```text
[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true

[*.md]
trim_trailing_whitespace = false
```

Therefore:

> **All repository text is UTF-8 without BOM, LF-only, with a final newline.**

Windows and PowerShell do not change this.

Generated repository text must:

1. remove UTF-8 BOM;
2. convert CRLF to LF;
3. convert lone CR to LF;
4. contain no `\r` bytes after normalization;
5. end in `\n`;
6. avoid accidental trailing whitespace;
7. preserve Markdown trailing spaces only when intentional.

PowerShell can execute LF scripts. Do not choose CRLF solely because the workstation is Windows.

---

## 3. Git output is machine data

### 3.1 Porcelain

Never apply `trim()` to the complete output of:

```text
git status --porcelain=v1
```

These are different states:

```text
 M file.php
M  file.php
?? file.php
```

The first two columns are semantic. Left trimming corrupts them.

If porcelain is required:

- normalize only CRLF/CR to LF;
- split records;
- preserve columns 1–2 exactly;
- parse the path independently.

### 3.2 Exact path scopes

Prefer:

```text
git diff --name-only --
git diff --cached --name-only --
git ls-files --others --exclude-standard
git diff-tree --no-commit-id --name-only -r <sha> --
```

For path-only output:

- normalize line separators;
- split;
- discard empty records;
- trim each path record only;
- sort using string sort;
- compare exact arrays.

Never compare raw multiline output when the intended contract is a set of paths.

---

## 3.3 Windows command-shell metacharacters

The primary development workstation is Windows. PHP `exec()` / `shell_exec()` command strings are interpreted by `cmd.exe` before the child program receives them.

Therefore Git revision syntax that contains a caret is unsafe inside those shell strings.

Forbidden examples:

```text
git rev-parse HEAD^
git rev-parse <sha>^
git show HEAD^{commit}
```

In `cmd.exe`, `^` is an escape metacharacter. A runner can therefore believe it asked Git for a parent while Git actually receives a different argument.

Required alternatives:

```text
git rev-parse HEAD~1
git rev-parse <exact-full-sha>~1
```

For post-commit verification, the preferred contract is even stronger:

```text
PRE_COMMIT_HEAD=<exact sha captured before commit>
POST_COMMIT_PARENT=$(git rev-parse HEAD~1)
POST_COMMIT_PARENT must equal PRE_COMMIT_HEAD
```

Permanent rules:

- never use caret-based Git revision expressions in Windows shell command strings;
- use `~1` for first-parent resolution;
- use exact full SHAs whenever possible;
- treat `^ & | < > ( ) % !` as shell-sensitive characters;
- when shell-sensitive syntax is truly needed, prefer a shell-free process/argv API rather than string concatenation;
- do not diagnose a Git-history anomaly until the actual commit graph has been inspected independently of the potentially broken shell expression.

This rule exists because a false `Commit parent mismatch` was produced on 2026-09-07 when `git rev-parse HEAD^` was executed through a Windows PHP runner. The actual local commit graph was correct; the shell expression was not.

---

## 4. Reusable machine guard

All new PHP development runners should use:

```text
tools/StraleonRunnerGuard.php
```

for:

- LF normalization;
- UTF-8/BOM/final-newline assertions;
- Git `--name-only` parsing;
- porcelain parsing;
- exact path-set assertions;
- self-tests.

Before a mutation runner relying on it mutates the repository:

```powershell
php tools/StraleonRunnerGuard.php --self-test
```

must be GREEN.

---

## 5. Artifact preflight

Do not call an artifact ready merely because its outer PHP file lints.

Before telling the operator to execute a source-write artifact:

1. physical file exists;
2. exact size is known;
3. SHA-256 is calculated from that real file;
4. outer artifact lints;
5. embedded PHP payloads lint when applicable;
6. EOL normalization is exercised;
7. CRLF and LF path parsing are exercised;
8. porcelain parsing is exercised if porcelain is used;
9. expected scope comparison is exercised;
10. rollback structure is validated;
11. the real download link is available.

If these are not true, do not call the artifact fully preflighted.

---

## 6. Baseline gates

Before mutation require, as applicable:

```text
EXPECTED_BRANCH
EXPECTED_HEAD
EXPECTED WORKTREE STATE
EXPECTED STAGING STATE
EXPECTED BASELINE BLOBS
EXPECTED TARGET EXISTENCE / NON-EXISTENCE
```

Use `git hash-object` / blob identity for existing files.

Prefer exact baseline + precise change over fragile textual anchors.

If an anchor is unavoidable:

- baseline blob first;
- anchor exactly once;
- fail with useful diagnostics;
- rollback cleanly.

---

## 7. No unrelated formatting churn

A small semantic change must not become a huge formatting rewrite by accident.

- preserve untouched lines when practical;
- do not reformat unrelated code;
- declare intentional formatting as scope;
- run `git diff --check`;
- investigate unexpectedly large diffs before checkpoint.

---

## 8. Rollback

Before commit:

- snapshot modified-file bytes;
- record new files;
- restore/remove them on failure;
- restore staging to the expected pre-run state.

After commit:

- never silently rewrite history;
- if verification fails before push, stop and report the local commit;
- if push occurred, never auto-reset or force-push as implicit rollback.

---

## 9. Tests

For source changes:

1. focused tests;
2. full suite once;
3. if source did not change, do not rerun GREEN tests;
4. if source changed afterward, rerun affected evidence.

A runner/tooling failure that rolls back without changing source does not invalidate existing GREEN test evidence.

Documentation-only cuts do not require another local full suite when the immediately preceding source checkpoint is already GREEN and natural CI will run.

---

## 10. Checkpoint and publication

After source evidence is GREEN, one coordinated runner may do:

```text
baseline
→ exact scope
→ diff check
→ stage
→ staged-scope check
→ commit
→ parent/subject/scope verification
→ push
```

Do not re-run already-GREEN tests inside that checkpoint runner.

After push:

- verify remote exact SHA;
- verify remote parent/subject/scope;
- consume natural CI;
- use connected GitHub access when available instead of making the operator poll.

---

## 11. High-velocity rule

Preferred interaction unit:

> **one logical cut per fail-closed runner**

Avoid:

```text
status → response
lint → response
test → response
add → response
commit → response
push → response
```

Microcommands are reserved for real anomalies that cannot be resolved safely from existing evidence.

---

## 12. Master continuity

The three continuity masters are:

```text
docs/06_ROADMAP.md
docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md
docs/README.md
```

After meaningful published progression, synchronize them before letting roadmap state drift materially behind code.

---

## 13. Windows-specific permanent rule

```text
PowerShell/Git console output may contain CRLF
≠
repository files should use CRLF
```

Repository rules come from `.gitattributes` and `.editorconfig`, never from terminal line endings.

---

## 14. Failure classification

Before changing product code, classify a failure:

```text
PRODUCT / DOMAIN
TEST
RUNNER / TOOLING
ENVIRONMENT
BASELINE DRIFT
REMOTE / CI
```

Never change Straleon domain logic to fix a runner parsing bug.

---

## 15. Final rule

Before generating a runner, ask the repository what the rule is.

Do not rely on memory for:

- line endings;
- encoding;
- whitespace;
- HEAD;
- blob hashes;
- scope;
- CI;
- roadmap boundary.

The repository and published evidence are authoritative.
