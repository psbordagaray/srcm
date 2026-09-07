# Straleon — Binding Agent / Automation Contract

This file is binding for any AI assistant, coding agent, generated runner, maintenance script, or automation that modifies this repository.

Before generating or executing any repository mutation, read:

1. `.gitattributes`
2. `.editorconfig`
3. `docs/153_STRALEON_ENGINEERING_EXECUTION_CONTRACT_V1.md`
4. `tools/StraleonRunnerGuard.php`

## Repository text contract

- Repository text is UTF-8 without BOM.
- Repository text uses LF (`\n`) line endings.
- Repository text ends with a final newline.
- Never generate CRLF repository text merely because the operator uses Windows.
- `.gitattributes` declares `* text=auto eol=lf`.
- `.editorconfig` declares `charset=utf-8`, `end_of_line=lf`, `insert_final_newline=true`.
- Markdown may preserve intentional trailing whitespace; generated Markdown should not add it unless intentional.

## Git-output parsing contract

- Never call `trim()` or left-trim on complete `git status --porcelain` output.
- The first two porcelain columns are semantic status bytes.
- Prefer path-only scope commands:
  - `git diff --name-only --`
  - `git diff --cached --name-only --`
  - `git ls-files --others --exclude-standard`
  - `git diff-tree --no-commit-id --name-only -r <sha> --`
- Normalize only line separators before splitting Git output.
- Use `tools/StraleonRunnerGuard.php` for path parsing, porcelain parsing, LF normalization and exact-scope assertions.

## Runner contract

Every mutation runner must have:

- exact expected branch;
- exact expected HEAD;
- exact baseline blob hashes for existing files it mutates;
- explicit expected worktree/staging state;
- exact dirty/staged/commit scope;
- `git diff --check`;
- safe rollback before commit;
- no unrelated formatting churn;
- physical artifact existence, byte size, SHA-256 and syntax checks before execution is requested;
- helper self-tests for EOL/path parsing when relevant.

Never rerun already-GREEN tests unless source changed.

## Working cadence

Prefer one fail-closed runner per logical cut:

`preflight → mutation → validation → stage → commit → verify → push`

Avoid unnecessary terminal ping-pong.

If this file conflicts with an assumption in a generated artifact, this file wins.
