# Agent Instructions

<fuel>
## Fuel Task Management

This project uses **Fuel** for agent orchestration, epic management, and task tracking.

### Quick Reference

```bash
fuel status                      # View brief overview of the daemons and in progress tasks
fuel add "Task title" [--blocked-by=f-id]           # Add a new task
fuel add "Idea" --someday       # Add to backlog (future work)
fuel show <id>                  # View task details
fuel consume --once             # Kanban view
fuel tree                       # Tree view
fuel backlog                    # List backlog items
fuel promote <b-id>             # Promote backlog item to task
fuel defer <f-id>               # Move task to backlog
fuel dep:add <id> <blocker>     # Add dependency
fuel dep:remove <id> <blocker>  # Remove dependency
```

You must never work on fuel tasks you add in-session. Fuel will pickup and manage tasks. You work on non-fuel tasks when requested.

### TodoWrite vs Fuel

Use **TodoWrite** for single-session step tracking. Use **fuel** for work that anything moderately complex or that outlives the session (multi-session, dependencies, discovered work for future). When unsure, prefer fuel.

### Epic Plan Files

Plans are stored in `.fuel/plans/{epic-title-kebab}-{epic-id}.md` and committed to git.

**When planning an epic:**
1. Ask the user if they'd like it selfguided or parallel.
2. Create epic: `fuel epic:add "Feature name" [--selfguided]` to get the ID
3. Merge your plan to the file path given based on its structure (`.fuel/plans/{title-kebab}-{epic-id}.md`)
    - there is a different structure depending on the task, it's important you merge your thinking into the existing structure
4. If non-selfguided: breakdown the epic into well defined tasks (use the skill)

**Epic review tasks are MANDATORY for non-selfguided epics.** Always use `--complexity=complex` and list acceptance criteria:

```bash
fuel add "Review: Feature name" \
  --epic=e-xxxx \
  --complexity=complex \
  --blocked-by=f-task1,f-task2,... \
  --description="Verify epic complete. Acceptance criteria: 1) [behavior], 2) [API works], 3) [errors handled], 4) All tests pass: vendor/bin/pest path/to/tests"
```

**Review tasks must verify:**
1. **Intent** - Does it match the epic description? Would the user be happy?
2. **Correctness** - Do behaviors work? Tests pass? Edge cases handled?
3. **Quality** - No debug calls (dd, console.log), no useless comments, follows patterns

Parallel example:
```bash
fuel epic:add "Add user preferences"    # Create epic (note the ID)
fuel add "Add preferences API" --epic=e-xxxx -e e-xxxx  # Link task
fuel add "Add preferences UI" --epic=e-xxxx --blocked-by=f-xxxx             # Link another
fuel epics                               # List all epics with status
fuel epic:show <e-id>                   # View epic + linked tasks
fuel epic:reviewed <e-id>               # Mark as human-reviewed
```

When parallel tasks share an interface, define it in a parent task's description. Avoid parallel work on tasks touching same files - use dependencies instead.
**Always use epics for multi-task work.** Standalone tasks are fine for single-file fixes.

### Task Options

```bash
fuel add "Title" --description="..." --type=bug|fix|feature|task|epic|chore|docs|test|refactor --priority=0|1|2|3|4 --blocked-by=f-xxxx --labels=api,urgent --complexity=trivial|simple|moderate|complex --epic=e-xxxx --status=open|in_progress|review|done|cancelled|someday|paused
```
**Always set `--complexity`:** `trivial` (typos) | `simple` (single focus) | `moderate` (multiple files) | `complex` (multiple files, requires judgement or careful coordination)

### Writing Good Descriptions

Descriptions should be explicit enough for a less capable agent to complete without guessing. Include: files to modify (exact paths), what to change (methods, patterns), expected behavior, and patterns to follow. **Give one clear solution, not options—subagents execute, they don't decide.**

**Bad**: "Fix the ID display bug"
**Good**: "BoardCommand.php:320 uses substr($id, 5, 4) for old format. Change to substr($id, 2, 6) for f-xxxxxx format."

### Backlog Management

The backlog is for **rough ideas and future work** that isn't ready to implement yet. Tasks are for **work ready to implement now**.

**When to use backlog vs tasks:**
- **Backlog (`fuel add --someday`)**: Rough ideas, future enhancements, "nice to have" features, exploratory concepts, work that needs more thought before implementation
- **Tasks (`fuel add`)**: Work that's ready to implement now, has clear requirements, can be started immediately

### Testing Visual Changes with Browser
Use the fuel browser testing skill. If you don't have the skill, run fuel --help | grep -i browser.

fuel includes a browser daemon for testing webpages:

**Tips:**
- Screenshots are saved to `/tmp` by default, specify custom paths as needed
- Browser daemon auto-manages lifecycle, no manual cleanup needed

### Git Commits
- Quality gates enforced via pre-commit hook
- FORBIDDEN: `git commit --no-verify`, `git commit -n` (bypasses quality checks)</fuel>
