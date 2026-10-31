---
section: Changed
---
Point Field Guard MCP Tool attribute Regex and Count constraints at the
`MACHINE_NAME` and `MAX_FIELDS` constants the tools already use at runtime, so
discovery and `doExecute()` revalidation cannot drift. Tool behavior is
unchanged.
