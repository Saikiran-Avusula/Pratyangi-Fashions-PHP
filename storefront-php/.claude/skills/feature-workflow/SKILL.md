---
name: feature-workflow
description: Plan, implement, test and review a significant change to the PHP storefront using the custom architect, developer, tester and reviewer subagents with approval gates, file snapshots and a check against docs/API_CONTRACT.md.
disable-model-invocation: true
argument-hint: <task description>
---
Task: $ARGUMENTS

You are the Orchestrator. This is a custom-subagent workflow: no native Agent Team, no shared task list. Follow CLAUDE.md safety rules throughout. `docs/API_CONTRACT.md` is the source of truth: the PHP API must return exactly the JSON the Node API returns. The Node project is a read-only reference and must never be edited.

1. **Baseline.** This folder has no commits yet, so do not use `git status` or `git diff` as evidence. Ask the user whether any file you may touch already has changes they want kept, and list `.claude/snapshots/` to see earlier work. Confirm which build phase (CLAUDE.md) the task belongs to; refuse to start a later phase early without the user's say-so. Read only what you need.
2. **Classify.** Simple = small, localized, low-risk (copy tweak, single-file fix). Significant = multi-file, new behaviour, money/auth/session/payment/data/API/schema, or risky. Simple tasks: do them yourself, but still snapshot each file before editing it (see the developer agent's protocol), run `php -l` on it, and go to step 8.
3. **Plan (significant).** Spawn `architect` once with the task, the relevant contract sections and paths. Show the user the plan, the exact file list, risks and test plan, then **stop and wait for an explicit approval**. Revise if asked.
4. **Implement.** Spawn `developer` with the approved plan verbatim plus the exact allowed file scope. It must snapshot each file before editing and keep `CHANGES.md`. If it reports a blocker or needs an install, database command or permission, return to the user.
5. **Evidence.** Yourself: read `.claude/snapshots/<task>/CHANGES.md`; for each MODIFIED file run `diff -u .claude/snapshots/<task>/<path> <path>`; read each NEW file. Check that nothing outside the manifest or the approved scope changed (look at file modification times if in doubt). Flag anything outside the scope. Spot-check the response shapes against the contract sections yourself.
6. **Independent checks.** Spawn `tester` (approved narrow php scripts and `php -l` only) and `reviewer`. Give the reviewer the approved requirements, the contract sections, the diffs from step 5, the full contents of new files, `CHANGES.md` and the test results, since it has no shell. Run them in parallel only when the tests don't modify files; otherwise tester first.
   - A test that touches MySQL needs the user's approval and a throwaway database: confirm only host, port and database name (never user, password or full connection string), that the port is not 3306 and that the name ends in `_test`. If that can't be established safely, stop and ask.
   - Razorpay, the ERP and SMTP are only ever local fakes. Comparing against the Node reference API needs the user to have started it on localhost.
7. **Blockers first.** Present blockers and important findings from tester/reviewer to the user before any further fix round; at most one fix loop unless told otherwise. Re-run only affected checks.
8. **Summarize.** Verify the evidence yourself rather than relaying claims. Report: every file changed (with its snapshot path), exact test commands with exit codes and skips, review findings, contract differences, unresolved risks, what was not verified. Say "passed" only for checks that ran and exited 0.
9. **Approval gates.** Ask the user before any commit, install, migration, database command, delete or move, or anything touching production. Never push and never change Git remotes (also blocked by `.claude/settings.json`).

Spawn only the agents the task needs. When you tell the user about work, include the estimated time and which agents will run (and, for background agents, their timelines).
