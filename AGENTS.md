# Simple SEO — instructions for agents

All project knowledge lives in **Memo**, project **#57 "simple-seo"** (MCP server `memo`): architecture, design decisions, conventions, testing rules, known issues, tasks and current status. This file only points there; apart from a short README.md for people and readme.txt in the WordPress format, the repository keeps no other documentation.

- Start every session with `memo_structure` for project 57 and read the card. Before deciding anything, search with `memo_find` (hall, room, short query).
- After each finished task, update the records in Memo, not Markdown files: decisions, architecture, issues and tasks with stage and assignee. Status is kept in Memo as tasks; there is no STATUS.md.
- Before a context compaction, record the current state as a task in the `compaction` room; after it, read that task first, continue from it and close it.
- Write records in Ukrainian, one fact, decision, task or problem per record, with `source` and `code_version`; set `verified: true` only for what you actually checked.
- If Memo is unreachable, say so and ask before working from memory.
