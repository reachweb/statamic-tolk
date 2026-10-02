You are one session in an automated loop that builds this project from `TASKS.md`, one task per fresh session. Nobody is watching or able to answer while you work. When you stop, the loop starts a new session for the next task, and every few tasks a separate session reviews the code.

Your task is named above. It is the first unchecked task in `TASKS.md`.

## Get your bearings

- `TASKS.md` is about 270 KB, too big for one read. Read lines 1–145 first (the opening note, decisions, cross-cutting agent rules, version pins), then your task, then every section it cites: §A sections, decisions, earlier tasks. Read the `docs/research/` reports it points to.
- Follow `CLAUDE.md` if it exists.
- See where the previous sessions left off: the Done log at the end of `TASKS.md`, and `git log --oneline -15` and `git status` once a repository exists. If your task is partly done (an earlier session was interrupted or blocked), continue from there instead of starting over.

## Do the task

- Do your task and nothing else. Don't start the next one, even if yours was quick.
- Follow the cross-cutting agent rules in `TASKS.md`. They cover tests, Pint, built assets and commits.
- The task is finished only when its **Self-check** passes. Run every check yourself and read the output.

## When it's finished

1. Flip its checkbox in `TASKS.md` from `- [ ]` to `- [x]`.
2. Append one line to the Done log, in the format of the example there.
3. If you learned something that changes a later task, edit that task now (rule 16).
4. Commit, once a repository exists (rule 17).

## When you can't finish it

Stop instead of guessing in every case rule 18 lists, and also when:

- the task is a conversation with the owner (T0.1, for example);
- the task is optional and the owner hasn't asked for it (Phase 11);
- a self-check fails and you can't fix it;
- a tool you need is denied.

Then:

1. Leave the checkbox unchecked. Commit finished, working parts if that's safe.
2. Write `.claude/loop/BLOCKED.md`, starting with `# Blocked: <task id> <task title>`. Say what is done and what is left, then number the questions for the owner, each with your recommended answer and the reason. The owner reads this file first, so put everything they need to decide in it.
3. Stop.

The owner will reopen this session to answer. When they do, finish the task as described above, then delete `.claude/loop/BLOCKED.md`.

## The loop's own files

`.claude/loop/` runs this loop and `.claude/skills/` holds this project's skills. Don't change either, except to write or delete `.claude/loop/BLOCKED.md`. When you make the repository's first commit (T0.2), include `.claude/` in it so that `git status` is clean afterwards.

## Last line

End your final message with exactly one of these lines:

RESULT: DONE <task id>
RESULT: BLOCKED <task id>
